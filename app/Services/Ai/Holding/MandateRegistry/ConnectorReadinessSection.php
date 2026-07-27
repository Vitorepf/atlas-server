<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class ConnectorReadinessSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function domainDataFabricStatus(?string $companyId = null): array
    {
        $companyRows = array_values(array_map(
            fn (array $company): array => $this->domainDataFabricCompany((array) $company),
            $this->hub->buildoutCompanies($companyId),
        ));

        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::DOMAIN_DATA_FABRIC_STATUS_SCHEMA,
            'domain_data_fabric_ready_external_mutation_blocked',
            'domain_data_fabric_attention_required',
            [
                'source_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_count'], $companyRows)),
                'provider_count' => array_sum(array_map(static fn (array $company): int => (int) $company['provider_count'], $companyRows)),
                'data_product_count' => array_sum(array_map(static fn (array $company): int => (int) $company['data_product_count'], $companyRows)),
                'flow_workbench_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_workbench_count'], $companyRows)),
                'decision_packet_factory_count' => array_sum(array_map(static fn (array $company): int => (int) $company['decision_packet_factory_count'], $companyRows)),
                'enablement_track_count' => array_sum(array_map(static fn (array $company): int => (int) $company['enablement_track_count'], $companyRows)),
                'observability_metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['observability_metric_count'], $companyRows)),
                'external_mutation_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'direct_source_link_required_for_every_claim' => true,
                'cross_source_verification_required' => true,
                'private_or_regulated_data_requires_redaction' => true,
                'connector_probe_required_before_live_read' => true,
                'external_data_mutation_allowed' => false,
                'external_write_spend_trade_publish_deploy_delete_allowed' => false,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['claim_without_source_link', 'skip_cross_source_check', 'live_read_without_probe', 'external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
            ],
            'domain_data_fabric_status_hash',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function domainDataConnectorOperatingStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $runtimeStatus = $this->hub->flowActionRuntime->domainDataConnectorOperatingRuntimeStatus($wantedCompany);
        $runtimeByCompany = $this->hub->companyRowsById($runtimeStatus);

        $companyRows = array_values(array_map(
            fn (array $company): array => $this->domainDataConnectorOperatingCompany(
                (array) $company,
                (array) ($runtimeByCompany[(string) ($company['company_id'] ?? 'unknown')] ?? []),
            ),
            $this->hub->buildoutCompanies($wantedCompany),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::DOMAIN_DATA_CONNECTOR_OPERATING_STATUS_SCHEMA,
            'domain_data_connector_operating_ready_external_mutation_blocked',
            'domain_data_connector_operating_attention_required',
            [
                'source_data_room_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_data_room_count'], $companyRows)),
                'domain_data_product_count' => array_sum(array_map(static fn (array $company): int => (int) $company['domain_data_product_count'], $companyRows)),
                'connector_permission_profile_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_permission_profile_count'], $companyRows)),
                'flow_data_connector_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_data_connector_contract_count'], $companyRows)),
                'ready_flow_data_connector_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_flow_data_connector_contract_count'], $companyRows)),
                'connector_fixture_eval_suite_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_fixture_eval_suite_count'], $companyRows)),
                'ready_connector_fixture_eval_suite_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_connector_fixture_eval_suite_count'], $companyRows)),
                'required_control_count' => array_sum(array_map(static fn (array $company): int => (int) $company['required_control_count'], $companyRows)),
                'observability_metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['observability_metric_count'], $companyRows)),
                'runtime_completed_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['runtime_completed_flow_count'], $companyRows)),
                'external_mutation_allowed_count' => 0,
                'write_tools_enabled_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'read_only_probe_required_before_live_use' => true,
                'write_tools_enabled' => false,
                'external_data_mutation_allowed' => false,
                'secret_export_allowed' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'connector_permission_profile_required_before_runtime_use' => true,
                'fixture_eval_required_before_live_connector_use' => true,
                'blocked_operations' => ['live_connector_without_permission_profile', 'live_read_without_probe', 'external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export', 'offensive_security'],
            ],
            'domain_data_connector_operating_status_hash',
            [
                'domain_data_connector_operating_runtime_status_hash' => $runtimeStatus['domain_data_connector_operating_runtime_status_hash'] ?? null,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function flowLiveReadConnectorProbeStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $runtimeStatus = $this->hub->flowActionRuntime->flowLiveReadConnectorProbeRuntimeStatus($wantedCompany);
        $runtimeByCompany = $this->hub->companyRowsById($runtimeStatus);

        $companyRows = array_values(array_map(
            fn (array $company): array => $this->flowLiveReadConnectorProbeCompany(
                (array) $company,
                (array) ($runtimeByCompany[(string) ($company['company_id'] ?? 'unknown')] ?? []),
            ),
            $this->hub->buildoutCompanies($wantedCompany),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::FLOW_LIVE_READ_CONNECTOR_PROBE_STATUS_SCHEMA,
            'flow_live_read_connector_probe_ready_external_mutation_blocked',
            'flow_live_read_connector_probe_attention_required',
            [
                'connector_probe_profile_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_probe_profile_count'], $companyRows)),
                'flow_live_read_probe_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_live_read_probe_contract_count'], $companyRows)),
                'ready_flow_live_read_probe_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_flow_live_read_probe_contract_count'], $companyRows)),
                'flow_probe_evidence_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_probe_evidence_matrix_count'], $companyRows)),
                'ready_flow_probe_evidence_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_flow_probe_evidence_matrix_count'], $companyRows)),
                'observability_metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['observability_metric_count'], $companyRows)),
                'runtime_completed_probe_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['runtime_completed_probe_flow_count'], $companyRows)),
                'external_mutation_allowed_count' => 0,
                'write_tools_enabled_count' => 0,
                'credential_material_in_packet_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'live_read_allowed' => true,
                'write_tools_enabled' => false,
                'external_mutation_allowed' => false,
                'credential_material_in_packet_allowed' => false,
                'operator_scope_required_before_live_connector_probe' => true,
                'operator_mandate_required_for_any_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'promotion_unlocked' => 'shadow_readiness_not_external_write_authority',
                'blocked_operations' => ['live_probe_without_operator_scope', 'live_probe_without_permission_report', 'credential_material_in_packet', 'external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'secret_export', 'offensive_security'],
            ],
            'flow_live_read_connector_probe_status_hash',
            [
                'flow_live_read_connector_probe_runtime_status_hash' => $runtimeStatus['flow_live_read_connector_probe_runtime_status_hash'] ?? null,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function externalResearchAdoptionStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $runtimeStatus = $this->hub->flowActionRuntime->externalResearchAdoptionRuntimeStatus($wantedCompany);
        $runtimeByCompany = $this->hub->companyRowsById($runtimeStatus);

        $companyRows = array_values(array_map(
            fn (array $company): array => $this->externalResearchAdoptionCompany(
                (array) $company,
                (array) ($runtimeByCompany[(string) ($company['company_id'] ?? 'unknown')] ?? []),
            ),
            $this->hub->buildoutCompanies($wantedCompany),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::EXTERNAL_RESEARCH_ADOPTION_STATUS_SCHEMA,
            'external_research_adoption_ready_external_effects_blocked',
            'external_research_adoption_attention_required',
            [
                'source_basis_count' => array_sum(array_map(static fn (array $company): int => (int) $company['source_basis_count'], $companyRows)),
                'official_framework_repository_count' => array_sum(array_map(static fn (array $company): int => (int) $company['official_framework_repository_count'], $companyRows)),
                'domain_repository_candidate_count' => array_sum(array_map(static fn (array $company): int => (int) $company['domain_repository_candidate_count'], $companyRows)),
                'flow_adoption_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_adoption_matrix_count'], $companyRows)),
                'ready_flow_adoption_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_flow_adoption_matrix_count'], $companyRows)),
                'capability_map_count' => array_sum(array_map(static fn (array $company): int => (int) $company['capability_map_count'], $companyRows)),
                'connector_backlog_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_backlog_count'], $companyRows)),
                'production_gate_group_count' => array_sum(array_map(static fn (array $company): int => (int) $company['production_gate_group_count'], $companyRows)),
                'observability_metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['observability_metric_count'], $companyRows)),
                'runtime_completed_adoption_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['runtime_completed_adoption_flow_count'], $companyRows)),
                'external_side_effects_enabled_count' => 0,
                'unreviewed_runtime_ingestion_allowed_count' => 0,
                'unsafe_repository_adoption_allowed_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_research_is_architecture_input_only' => true,
                'runtime_ingestion_without_source_review_allowed' => false,
                'repository_adoption_without_license_security_and_fixture_eval_allowed' => false,
                'external_side_effects_default' => false,
                'external_execution_allowed' => false,
                'operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action' => true,
                'blocked_operations' => ['unreviewed_source_ingestion', 'unreviewed_repository_adoption', 'runtime_use_without_fixture_eval', 'external_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export', 'offensive_security'],
            ],
            'external_research_adoption_status_hash',
            [
                'external_research_adoption_runtime_status_hash' => $runtimeStatus['external_research_adoption_runtime_status_hash'] ?? null,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function flowBenchmarkReplayStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $runtimeStatus = $this->hub->flowActionRuntime->flowBenchmarkReplayRuntimeStatus($wantedCompany);
        $runtimeByCompany = $this->hub->companyRowsById($runtimeStatus);

        $companyRows = array_values(array_map(
            fn (array $company): array => $this->flowBenchmarkReplayCompany(
                (array) $company,
                (array) ($runtimeByCompany[(string) ($company['company_id'] ?? 'unknown')] ?? []),
            ),
            $this->hub->buildoutCompanies($wantedCompany),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::FLOW_BENCHMARK_REPLAY_STATUS_SCHEMA,
            'flow_benchmark_replay_ready_external_benchmark_blocked',
            'flow_benchmark_replay_attention_required',
            [
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_count'], $companyRows)),
                'offline_dataset_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['offline_dataset_contract_count'], $companyRows)),
                'ready_offline_dataset_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_offline_dataset_contract_count'], $companyRows)),
                'trace_grading_rubric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['trace_grading_rubric_count'], $companyRows)),
                'ready_trace_grading_rubric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_trace_grading_rubric_count'], $companyRows)),
                'adversarial_regression_case_count' => array_sum(array_map(static fn (array $company): int => (int) $company['adversarial_regression_case_count'], $companyRows)),
                'ready_adversarial_regression_case_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_adversarial_regression_case_count'], $companyRows)),
                'deterministic_state_assertion_count' => array_sum(array_map(static fn (array $company): int => (int) $company['deterministic_state_assertion_count'], $companyRows)),
                'ready_deterministic_state_assertion_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_deterministic_state_assertion_count'], $companyRows)),
                'replay_comparison_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['replay_comparison_matrix_count'], $companyRows)),
                'ready_replay_comparison_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['ready_replay_comparison_matrix_count'], $companyRows)),
                'benchmark_observability_metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['benchmark_observability_metric_count'], $companyRows)),
                'runtime_completed_benchmark_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['runtime_completed_benchmark_flow_count'], $companyRows)),
                'external_benchmark_execution_allowed_count' => 0,
                'synthetic_score_claims_allowed_count' => 0,
                'promotion_without_replay_green_allowed_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_benchmark_execution_allowed' => false,
                'synthetic_score_claims_allowed' => false,
                'promotion_without_replay_green_allowed' => false,
                'external_model_or_paid_benchmark_requires_operator_approval' => true,
                'operator_mandate_required_for_external_benchmark_publish_spend_trade_deploy_delete_or_security_action' => true,
                'required_benchmark_surfaces' => ['offline_dataset_contract', 'trace_grading_rubric', 'adversarial_regression_case', 'deterministic_state_assertion', 'replay_comparison_matrix', 'benchmark_observability'],
                'blocked_operations' => ['synthetic_score_claim', 'promotion_without_replay_green', 'external_model_benchmark_without_operator_approval', 'external_benchmark_execution', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export', 'offensive_security'],
            ],
            'flow_benchmark_replay_status_hash',
            [
                'flow_benchmark_replay_runtime_status_hash' => $runtimeStatus['flow_benchmark_replay_runtime_status_hash'] ?? null,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function connectorCertificationPreflightStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $runtimeStatus = $this->hub->flowActionRuntime->connectorCertificationPreflightRuntimeStatus($wantedCompany);
        $runtimeByCompany = $this->hub->companyRowsById($runtimeStatus);

        $companyRows = array_values(array_map(
            fn (array $company): array => $this->connectorCertificationPreflightCompany(
                (array) $company,
                (array) ($runtimeByCompany[(string) ($company['company_id'] ?? 'unknown')] ?? []),
            ),
            $this->hub->buildoutCompanies($wantedCompany),
        ));
        return $this->hub->companyReadinessStatusPayload(
            $companyRows,
            ExternalActionMandateRegistryService::CONNECTOR_CERTIFICATION_PREFLIGHT_STATUS_SCHEMA,
            'connector_certification_preflight_ready_external_cutover_blocked',
            'connector_certification_preflight_attention_required',
            [
                'connector_count' => array_sum(array_map(static fn (array $company): int => (int) $company['connector_count'], $companyRows)),
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_count'], $companyRows)),
                'adapter_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['adapter_contract_count'], $companyRows)),
                'auth_boundary_count' => array_sum(array_map(static fn (array $company): int => (int) $company['auth_boundary_count'], $companyRows)),
                'sandbox_probe_count' => array_sum(array_map(static fn (array $company): int => (int) $company['sandbox_probe_count'], $companyRows)),
                'contract_test_count' => array_sum(array_map(static fn (array $company): int => (int) $company['contract_test_count'], $companyRows)),
                'data_lineage_count' => array_sum(array_map(static fn (array $company): int => (int) $company['data_lineage_count'], $companyRows)),
                'flow_connector_usage_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_connector_usage_count'], $companyRows)),
                'replay_fixture_count' => array_sum(array_map(static fn (array $company): int => (int) $company['replay_fixture_count'], $companyRows)),
                'slo_failure_mode_count' => array_sum(array_map(static fn (array $company): int => (int) $company['slo_failure_mode_count'], $companyRows)),
                'certification_metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['certification_metric_count'], $companyRows)),
                'production_preflight_contract_count' => array_sum(array_map(static fn (array $company): int => (int) $company['production_preflight_contract_count'], $companyRows)),
                'flow_cutover_matrix_count' => array_sum(array_map(static fn (array $company): int => (int) $company['flow_cutover_matrix_count'], $companyRows)),
                'production_evidence_register_count' => array_sum(array_map(static fn (array $company): int => (int) $company['production_evidence_register_count'], $companyRows)),
                'cutover_metric_count' => array_sum(array_map(static fn (array $company): int => (int) $company['cutover_metric_count'], $companyRows)),
                'runtime_completed_connector_flow_count' => array_sum(array_map(static fn (array $company): int => (int) $company['runtime_completed_connector_flow_count'], $companyRows)),
                'external_connector_cutover_allowed_count' => 0,
                'write_or_paid_mode_allowed_count' => 0,
                'real_credential_material_in_packet_allowed_count' => 0,
            ],
            [
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_connector_cutover_allowed' => false,
                'write_or_paid_mode_allowed_by_default' => false,
                'real_credential_material_in_packet_allowed' => false,
                'production_cutover_without_operator_signed_scope_allowed' => false,
                'operator_approval_required_for_write_publish_spend_trade_delete_or_secret_scope_expansion' => true,
                'manual_execution_handoff_only_after_signed_mandate' => true,
                'required_connector_surfaces' => ['adapter_contract', 'auth_boundary', 'sandbox_probe', 'consumer_provider_contract_test', 'data_lineage', 'flow_usage_matrix', 'replay_fixture', 'slo_failure_mode', 'production_preflight_contract', 'flow_cutover_matrix', 'production_evidence_register'],
                'blocked_operations' => ['external_connector_cutover', 'write_without_operator_scope', 'publish_without_operator_scope', 'spend_without_operator_scope', 'trade_without_operator_scope', 'delete_without_operator_scope', 'admin_scope_expansion', 'secret_export', 'real_credential_material_in_packet', 'deploy'],
            ],
            'connector_certification_preflight_status_hash',
            [
                'connector_certification_preflight_runtime_status_hash' => $runtimeStatus['connector_certification_preflight_runtime_status_hash'] ?? null,
            ],
        );
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    public function domainDataFabricCompany(array $company): array
    {
        $fabric = (array) data_get($company, 'enterprise_domain_data_fabric_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $workProductCount = count((array) ($company['work_products'] ?? []));
        $sources = (array) data_get($fabric, 'domain_data_source_catalog', []);
        $providers = (array) data_get($fabric, 'connector_data_provider_matrix', []);
        $dataProducts = (array) data_get($fabric, 'domain_data_products', []);
        $workbenches = (array) data_get($fabric, 'flow_data_workbenches', []);
        $factories = (array) data_get($fabric, 'flow_decision_packet_factories', []);
        $enablementTracks = (array) data_get($fabric, 'implementation_enablement_tracks', []);
        $metrics = (array) data_get($fabric, 'fabric_observability.required_metrics', []);

        $flowWorkbenchesReady = count(array_filter($workbenches, static fn (array $workbench): bool => (string) ($workbench['schema'] ?? '') === 'atlas.ai.company.flow_domain_data_workbench.v1'
            && count((array) ($workbench['source_refs'] ?? [])) >= 1
            && count((array) ($workbench['data_product_refs'] ?? [])) >= 1
            && count((array) ($workbench['required_artifacts'] ?? [])) >= 5
            && (bool) ($workbench['external_side_effects_enabled'] ?? true) === false));
        $decisionFactoriesReady = count(array_filter($factories, static fn (array $factory): bool => count((array) ($factory['sections'] ?? [])) >= 8
            && in_array('direct_source_links', (array) ($factory['must_include'] ?? []), true)
            && (bool) ($factory['customer_visible_or_external_action_requires_operator'] ?? false)));

        $checks = [
            'fabric_schema_green' => (string) ($fabric['schema'] ?? '') === 'atlas.ai.company.enterprise_domain_data_fabric_stack.v1',
            'source_catalog_green' => count($sources) >= 5,
            'provider_matrix_green' => count($providers) >= $connectorCount && $connectorCount > 0,
            'data_products_green' => count($dataProducts) >= $workProductCount && $workProductCount > 0,
            'flow_workbenches_green' => $flowWorkbenchesReady >= $flowCount && $flowCount > 0,
            'decision_packet_factories_green' => $decisionFactoriesReady >= $flowCount && $flowCount > 0,
            'enablement_tracks_green' => count($enablementTracks) >= 8,
            'observability_green' => count($metrics) >= 7,
            'calendar_wait_removed' => (bool) data_get($fabric, 'fabric_policy.calendar_wait_blocker_enabled', true) === false,
            'direct_source_links_required' => (bool) data_get($fabric, 'fabric_policy.direct_source_link_required_for_every_claim', false),
            'cross_source_verification_required' => (bool) data_get($fabric, 'fabric_policy.cross_source_verification_required', false),
            'external_mutations_blocked' => (bool) data_get($fabric, 'fabric_policy.external_write_spend_trade_publish_deploy_delete_allowed', true) === false,
        ];

        $row = [
            'schema' => 'atlas.ai.company.domain_data_fabric_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'source_count' => count($sources),
            'provider_count' => count($providers),
            'data_product_count' => count($dataProducts),
            'flow_workbench_count' => count($workbenches),
            'ready_flow_workbench_count' => $flowWorkbenchesReady,
            'decision_packet_factory_count' => count($factories),
            'ready_decision_packet_factory_count' => $decisionFactoriesReady,
            'enablement_track_count' => count($enablementTracks),
            'observability_metric_count' => count($metrics),
            'source_ids' => array_values(array_map(
                static fn (array $source): string => (string) ($source['source_id'] ?? 'unknown_source'),
                $sources,
            )),
            'data_product_ids' => array_values(array_map(
                static fn (array $product): string => (string) ($product['data_product_id'] ?? 'unknown_data_product'),
                $dataProducts,
            )),
            'next_actions' => ['run_read_only_connector_probe', 'bind_source_link_receipts', 'run_cross_source_claim_check', 'export_decision_packet_trace', 'request_operator_scope_before_external_effect'],
            'external_data_mutation_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['domain_data_fabric_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $runtimeRow
     * @return array<string,mixed>
     */
    public function domainDataConnectorOperatingCompany(array $company, array $runtimeRow): array
    {
        $stack = (array) data_get($company, 'enterprise_domain_data_connector_operating_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $workProductCount = count((array) ($company['work_products'] ?? []));
        $metricCount = count((array) ($company['metrics'] ?? []));
        $sourceDataRooms = (array) data_get($stack, 'source_data_room_catalog', []);
        $dataProducts = (array) data_get($stack, 'domain_data_products', []);
        $permissionProfiles = (array) data_get($stack, 'connector_permission_profiles', []);
        $flowContracts = (array) data_get($stack, 'flow_data_connector_contracts', []);
        $fixtureEvalSuites = (array) data_get($stack, 'connector_fixture_eval_suites', []);
        $requiredControls = (array) data_get($stack, 'domain_data_room_operating_model.required_controls', []);
        $blockedExternalActions = (array) data_get($stack, 'domain_data_room_operating_model.blocked_external_actions', []);
        $observabilityMetrics = (array) data_get($stack, 'data_connector_observability.required_metrics', []);

        $readyContracts = count(array_filter($flowContracts, static fn (array $contract): bool => (string) ($contract['schema'] ?? '') === 'atlas.ai.company.flow_data_connector_contract.v1'
            && count((array) ($contract['required_connectors'] ?? [])) >= 1
            && count((array) ($contract['required_source_ids'] ?? [])) >= 1
            && count((array) ($contract['required_data_products'] ?? [])) >= 3
            && count((array) ($contract['data_contract_gates'] ?? [])) >= 6
            && (int) ($contract['minimum_fixture_cases'] ?? 0) >= 25
            && (string) ($contract['live_connector_mode'] ?? '') === 'read_only_probe_until_operator_mandate'
            && (bool) ($contract['external_mutation_allowed'] ?? true) === false
            && strlen((string) ($contract['contract_hash'] ?? '')) === 64));
        $readyFixtureSuites = count(array_filter($fixtureEvalSuites, static fn (array $suite): bool => (string) ($suite['schema'] ?? '') === 'atlas.ai.company.connector_fixture_eval_suite.v1'
            && count((array) ($suite['case_mix'] ?? [])) >= 8
            && (int) ($suite['minimum_case_count'] ?? 0) >= 25
            && count((array) ($suite['required_scores'] ?? [])) >= 6
            && (bool) ($suite['promotion_requires_green_eval'] ?? false)
            && strlen((string) ($suite['eval_hash'] ?? '')) === 64));
        $runtimeCompletedFlowCount = (int) ($runtimeRow['completed_domain_data_connector_flow_count'] ?? 0);

        $checks = [
            'connector_stack_schema_green' => (string) ($stack['schema'] ?? '') === 'atlas.ai.company.enterprise_domain_data_connector_operating_stack.v1',
            'connector_stack_hash_green' => strlen((string) ($stack['data_connector_stack_hash'] ?? '')) === 64,
            'source_data_room_catalog_green' => count($sourceDataRooms) >= 7,
            'domain_data_products_cover_work_products' => $workProductCount > 0 && count($dataProducts) >= $workProductCount,
            'connector_permission_profiles_cover_connectors' => $connectorCount > 0 && count($permissionProfiles) >= $connectorCount,
            'flow_data_connector_contracts_cover_flows' => $flowCount > 0 && count($flowContracts) >= $flowCount && $readyContracts >= $flowCount,
            'connector_fixture_eval_suites_cover_flows' => $flowCount > 0 && count($fixtureEvalSuites) >= $flowCount && $readyFixtureSuites >= $flowCount,
            'domain_data_room_operating_model_green' => count($requiredControls) >= 7 && count($blockedExternalActions) >= 9,
            'observability_green' => count($observabilityMetrics) >= ($metricCount + 7),
            'runtime_or_structural_coverage_green' => $runtimeCompletedFlowCount >= $flowCount || ($readyContracts >= $flowCount && $readyFixtureSuites >= $flowCount),
            'calendar_wait_removed' => (bool) data_get($stack, 'data_connector_policy.calendar_wait_blocker_enabled', true) === false,
            'read_only_probe_required' => (bool) data_get($stack, 'data_connector_policy.read_only_probe_required_before_live_use', false),
            'write_tools_blocked' => (bool) data_get($stack, 'data_connector_policy.write_tools_enabled', true) === false,
            'external_data_mutation_blocked' => (bool) data_get($stack, 'data_connector_policy.external_data_mutation_allowed', true) === false,
            'secret_export_blocked' => (bool) data_get($stack, 'data_connector_policy.secret_export_allowed', true) === false,
            'operator_mandate_required' => (bool) data_get($stack, 'data_connector_policy.operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action', false),
        ];

        $row = [
            'schema' => 'atlas.ai.company.domain_data_connector_operating_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'work_product_count' => $workProductCount,
            'source_data_room_count' => count($sourceDataRooms),
            'domain_data_product_count' => count($dataProducts),
            'connector_permission_profile_count' => count($permissionProfiles),
            'flow_data_connector_contract_count' => count($flowContracts),
            'ready_flow_data_connector_contract_count' => $readyContracts,
            'connector_fixture_eval_suite_count' => count($fixtureEvalSuites),
            'ready_connector_fixture_eval_suite_count' => $readyFixtureSuites,
            'required_control_count' => count($requiredControls),
            'blocked_external_action_count' => count($blockedExternalActions),
            'observability_metric_count' => count($observabilityMetrics),
            'runtime_completed_flow_count' => $runtimeCompletedFlowCount,
            'data_room_ids' => array_values(array_map(
                static fn (array $source): string => (string) ($source['data_room_id'] ?? 'unknown_data_room'),
                $sourceDataRooms,
            )),
            'connector_profile_ids' => array_values(array_map(
                static fn (array $profile): string => (string) ($profile['profile_id'] ?? 'unknown_connector_profile'),
                $permissionProfiles,
            )),
            'next_actions' => ['run_connector_fixture_eval_suite', 'run_read_only_probe', 'bind_source_lineage_receipts', 'export_permission_report', 'request_operator_scope_before_external_effect'],
            'external_data_mutation_allowed' => false,
            'write_tools_enabled' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['domain_data_connector_operating_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $runtimeRow
     * @return array<string,mixed>
     */
    public function flowLiveReadConnectorProbeCompany(array $company, array $runtimeRow): array
    {
        $stack = (array) data_get($company, 'enterprise_flow_live_read_connector_probe_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $metricCount = count((array) ($company['metrics'] ?? []));
        $profiles = (array) data_get($stack, 'connector_probe_profiles', []);
        $contracts = (array) data_get($stack, 'flow_live_read_probe_contracts', []);
        $evidenceMatrices = (array) data_get($stack, 'flow_probe_evidence_matrix', []);
        $observabilityMetrics = (array) data_get($stack, 'probe_observability.required_metrics', []);

        $readyProfiles = count(array_filter($profiles, static fn (array $profile): bool => (string) ($profile['schema'] ?? '') === 'atlas.ai.company.live_read_connector_probe_profile.v1'
            && count((array) ($profile['allowed_probe_modes'] ?? [])) >= 3
            && in_array('live_read_only', (array) ($profile['allowed_probe_modes'] ?? []), true)
            && count((array) ($profile['required_scope_artifacts'] ?? [])) >= 6
            && count((array) ($profile['blocked_operations'] ?? [])) >= 9
            && (bool) ($profile['credential_material_in_packet_allowed'] ?? true) === false
            && (bool) ($profile['external_mutation_allowed'] ?? true) === false
            && strlen((string) ($profile['profile_hash'] ?? '')) === 64));
        $readyContracts = count(array_filter($contracts, static fn (array $contract): bool => (string) ($contract['schema'] ?? '') === 'atlas.ai.company.flow_live_read_probe_contract.v1'
            && count((array) ($contract['connector_scope'] ?? [])) >= 1
            && count((array) ($contract['required_pre_probe_evidence'] ?? [])) >= 6
            && count((array) ($contract['required_probe_outputs'] ?? [])) >= 7
            && count((array) ($contract['failure_handling'] ?? [])) >= 5
            && (int) ($contract['minimum_probe_cases'] ?? 0) >= 12
            && (bool) ($contract['external_mutation_allowed'] ?? true) === false
            && strlen((string) ($contract['contract_hash'] ?? '')) === 64));
        $readyMatrices = count(array_filter($evidenceMatrices, static fn (array $matrix): bool => (string) ($matrix['schema'] ?? '') === 'atlas.ai.company.flow_live_read_probe_evidence_matrix.v1'
            && count((array) ($matrix['required_green_evidence'] ?? [])) >= 8
            && (string) ($matrix['promotion_stage_unlocked'] ?? '') === 'shadow_readiness_not_external_write_authority'
            && (bool) ($matrix['promotion_requires_operator_acceptance'] ?? false)
            && (bool) ($matrix['external_execution_authority_granted'] ?? true) === false
            && strlen((string) ($matrix['matrix_hash'] ?? '')) === 64));
        $runtimeCompletedProbeFlowCount = (int) ($runtimeRow['completed_flow_live_read_connector_probe_count'] ?? 0);

        $checks = [
            'probe_stack_schema_green' => (string) ($stack['schema'] ?? '') === 'atlas.ai.company.enterprise_flow_live_read_connector_probe_stack.v1',
            'probe_stack_hash_green' => strlen((string) ($stack['probe_stack_hash'] ?? '')) === 64,
            'connector_probe_profiles_cover_connectors' => $connectorCount > 0 && count($profiles) >= $connectorCount && $readyProfiles >= $connectorCount,
            'flow_live_read_probe_contracts_cover_flows' => $flowCount > 0 && count($contracts) >= $flowCount && $readyContracts >= $flowCount,
            'flow_probe_evidence_matrix_cover_flows' => $flowCount > 0 && count($evidenceMatrices) >= $flowCount && $readyMatrices >= $flowCount,
            'probe_observability_green' => count($observabilityMetrics) >= ($metricCount + 7),
            'runtime_or_structural_coverage_green' => $runtimeCompletedProbeFlowCount >= $flowCount || ($readyContracts >= $flowCount && $readyMatrices >= $flowCount),
            'calendar_wait_removed' => (bool) data_get($stack, 'probe_policy.calendar_wait_blocker_enabled', true) === false,
            'live_read_allowed' => (bool) data_get($stack, 'probe_policy.live_read_allowed', false),
            'write_tools_blocked' => (bool) data_get($stack, 'probe_policy.write_tools_enabled', true) === false,
            'external_mutation_blocked' => (bool) data_get($stack, 'probe_policy.external_mutation_allowed', true) === false,
            'credential_material_blocked' => (bool) data_get($stack, 'probe_policy.credential_material_in_packet_allowed', true) === false,
            'operator_scope_required' => (bool) data_get($stack, 'probe_policy.operator_scope_required_before_live_connector_probe', false),
            'operator_mandate_required' => (bool) data_get($stack, 'probe_policy.operator_mandate_required_for_any_external_write_spend_trade_publish_deploy_delete_or_security_action', false),
        ];

        $row = [
            'schema' => 'atlas.ai.company.flow_live_read_connector_probe_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'connector_probe_profile_count' => count($profiles),
            'ready_connector_probe_profile_count' => $readyProfiles,
            'flow_live_read_probe_contract_count' => count($contracts),
            'ready_flow_live_read_probe_contract_count' => $readyContracts,
            'flow_probe_evidence_matrix_count' => count($evidenceMatrices),
            'ready_flow_probe_evidence_matrix_count' => $readyMatrices,
            'observability_metric_count' => count($observabilityMetrics),
            'runtime_completed_probe_flow_count' => $runtimeCompletedProbeFlowCount,
            'connector_profile_ids' => array_values(array_map(
                static fn (array $profile): string => (string) ($profile['profile_id'] ?? 'unknown_probe_profile'),
                $profiles,
            )),
            'next_actions' => ['bind_operator_scope', 'capture_schema_snapshot', 'capture_sample_payload_hash', 'run_live_read_only_probe', 'fallback_to_fixture_on_failure'],
            'live_read_allowed' => true,
            'external_mutation_allowed' => false,
            'write_tools_enabled' => false,
            'credential_material_in_packet_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['flow_live_read_connector_probe_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $runtimeRow
     * @return array<string,mixed>
     */
    public function externalResearchAdoptionCompany(array $company, array $runtimeRow): array
    {
        $stack = (array) data_get($company, 'enterprise_external_research_adoption_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $sources = (array) data_get($stack, 'source_basis', []);
        $officialRepositories = (array) data_get($stack, 'repository_and_framework_catalog.official_framework_repositories', []);
        $domainRepositories = (array) data_get($stack, 'repository_and_framework_catalog.domain_repository_candidates', []);
        $flowMatrix = (array) data_get($stack, 'per_flow_adoption_matrix', []);
        $capabilityMap = (array) data_get($stack, 'source_to_company_capability_map', []);
        $connectorBacklog = (array) data_get($stack, 'connector_and_data_provider_backlog', []);
        $productionGates = (array) data_get($stack, 'productionization_gates', []);
        $observabilityMetrics = (array) data_get($stack, 'research_observability.required_metrics', []);

        $readyFlowMatrix = count(array_filter($flowMatrix, static fn (array $matrix): bool => count((array) ($matrix['source_refs'] ?? [])) >= 5
            && count((array) ($matrix['repository_refs'] ?? [])) >= 5
            && count((array) ($matrix['domain_repository_refs'] ?? [])) >= 3
            && count((array) ($matrix['agent_template_refs'] ?? [])) >= 1
            && count((array) ($matrix['connector_candidates'] ?? [])) >= 1
            && count((array) ($matrix['skills'] ?? [])) >= 5
            && count((array) ($matrix['subagent_roles'] ?? [])) >= 3
            && count((array) ($matrix['adoption_gates'] ?? [])) >= 5
            && count((array) ($matrix['blocked_until_gate_green'] ?? [])) >= 7
            && (bool) ($matrix['external_side_effects_enabled'] ?? true) === false
            && strlen((string) ($matrix['matrix_hash'] ?? '')) === 64));
        $readyCapabilityMap = count(array_filter($capabilityMap, static fn (array $capability): bool => count((array) ($capability['review_artifacts_required'] ?? [])) >= 5
            && (string) ($capability['adoption_state'] ?? '') === 'contract_candidate_until_local_evidence_green'
            && (bool) ($capability['external_side_effects_enabled'] ?? true) === false
            && strlen((string) ($capability['capability_hash'] ?? '')) === 64));
        $readyConnectorBacklog = count(array_filter($connectorBacklog, static fn (array $backlog): bool => count((array) ($backlog['required_artifacts'] ?? [])) >= 5
            && count((array) ($backlog['candidate_adapter_forms'] ?? [])) >= 4
            && (string) ($backlog['activation_model'] ?? '') === 'read_only_probe_fixture_mock_then_operator_mandate_for_any_mutation'
            && (bool) ($backlog['external_side_effects_enabled'] ?? true) === false
            && strlen((string) ($backlog['backlog_hash'] ?? '')) === 64));
        $runtimeCompletedFlowCount = (int) ($runtimeRow['completed_external_research_adoption_flow_count'] ?? 0);

        $checks = [
            'research_stack_schema_green' => (string) ($stack['schema'] ?? '') === 'atlas.ai.company.enterprise_external_research_adoption_stack.v1',
            'research_stack_hash_green' => strlen((string) ($stack['research_adoption_hash'] ?? '')) === 64,
            'source_basis_green' => count($sources) >= 12,
            'repository_catalog_green' => count($officialRepositories) >= 8 && count($domainRepositories) >= 3,
            'agent_operating_blueprint_green' => count((array) data_get($stack, 'domain_agent_operating_blueprint.required_components', [])) >= 7
                && strlen((string) data_get($stack, 'domain_agent_operating_blueprint.component_hash', '')) === 64,
            'flow_adoption_matrix_covers_flows' => $flowCount > 0 && count($flowMatrix) >= $flowCount && $readyFlowMatrix >= $flowCount,
            'capability_map_covers_sources' => count($sources) > 0 && count($capabilityMap) >= count($sources) && $readyCapabilityMap >= count($sources),
            'connector_backlog_covers_connectors' => $connectorCount > 0 && count($connectorBacklog) >= $connectorCount && $readyConnectorBacklog >= $connectorCount,
            'productionization_gates_green' => count($productionGates) >= 5
                && count((array) ($productionGates['contract_ready'] ?? [])) >= 3
                && count((array) ($productionGates['fixture_ready'] ?? [])) >= 3
                && count((array) ($productionGates['shadow_ready'] ?? [])) >= 3
                && count((array) ($productionGates['supervised_ready'] ?? [])) >= 3,
            'observability_green' => count($observabilityMetrics) >= 6,
            'runtime_or_structural_coverage_green' => $runtimeCompletedFlowCount >= $flowCount || $readyFlowMatrix >= $flowCount,
            'calendar_wait_removed' => (bool) data_get($stack, 'research_policy.calendar_wait_blocker_enabled', true) === false,
            'source_review_required' => (bool) data_get($stack, 'research_policy.runtime_ingestion_without_source_review_allowed', true) === false,
            'repo_adoption_review_required' => (bool) data_get($stack, 'research_policy.repository_adoption_without_license_security_and_fixture_eval_allowed', true) === false,
            'external_effects_blocked' => (bool) data_get($stack, 'research_policy.external_side_effects_default', true) === false,
            'operator_mandate_required' => (bool) data_get($stack, 'research_policy.operator_mandate_required_for_external_write_spend_trade_publish_deploy_delete_or_security_action', false),
        ];

        $row = [
            'schema' => 'atlas.ai.company.external_research_adoption_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'source_basis_count' => count($sources),
            'official_framework_repository_count' => count($officialRepositories),
            'domain_repository_candidate_count' => count($domainRepositories),
            'flow_adoption_matrix_count' => count($flowMatrix),
            'ready_flow_adoption_matrix_count' => $readyFlowMatrix,
            'capability_map_count' => count($capabilityMap),
            'ready_capability_map_count' => $readyCapabilityMap,
            'connector_backlog_count' => count($connectorBacklog),
            'ready_connector_backlog_count' => $readyConnectorBacklog,
            'production_gate_group_count' => count($productionGates),
            'observability_metric_count' => count($observabilityMetrics),
            'runtime_completed_adoption_flow_count' => $runtimeCompletedFlowCount,
            'source_ids' => array_values(array_map(
                static fn (array $source): string => (string) ($source['source_id'] ?? 'unknown_source'),
                $sources,
            )),
            'repository_urls' => array_values(array_map(
                static fn (array $repository): string => (string) ($repository['repository_url'] ?? 'unknown_repository'),
                $officialRepositories,
            )),
            'next_actions' => ['review_source_basis', 'review_repository_license_security', 'run_local_fixture_eval', 'bind_operator_acceptance', 'promote_only_after_receipts'],
            'external_research_is_architecture_input_only' => true,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['external_research_adoption_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $runtimeRow
     * @return array<string,mixed>
     */
    public function flowBenchmarkReplayCompany(array $company, array $runtimeRow): array
    {
        $stack = (array) data_get($company, 'enterprise_flow_benchmark_replay_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $offlineDatasets = (array) data_get($stack, 'offline_dataset_contracts', []);
        $traceRubrics = (array) data_get($stack, 'trace_grading_rubrics', []);
        $adversarialCases = (array) data_get($stack, 'adversarial_regression_cases', []);
        $stateAssertions = (array) data_get($stack, 'deterministic_state_assertions', []);
        $replayMatrix = (array) data_get($stack, 'replay_and_comparison_matrix', []);
        $observabilityMetrics = (array) data_get($stack, 'benchmark_observability.required_metrics', []);

        $readyOfflineDatasets = count(array_filter($offlineDatasets, static fn (array $dataset): bool => (int) ($dataset['minimum_examples'] ?? 0) >= 10
            && count((array) ($dataset['example_types'] ?? [])) >= 5
            && count((array) ($dataset['required_fields'] ?? [])) >= 5
            && (bool) ($dataset['reference_output_required'] ?? false)
            && strlen((string) ($dataset['dataset_hash'] ?? '')) === 64));
        $readyTraceRubrics = count(array_filter($traceRubrics, static fn (array $rubric): bool => count((array) ($rubric['graded_trace_components'] ?? [])) >= 6
            && count((array) ($rubric['score_keys'] ?? [])) >= 6
            && count((array) ($rubric['failure_modes'] ?? [])) >= 6
            && (float) ($rubric['minimum_score'] ?? 0.0) >= 0.86
            && strlen((string) ($rubric['rubric_hash'] ?? '')) === 64));
        $readyAdversarialCases = count(array_filter($adversarialCases, static fn (array $case): bool => count((array) ($case['case_types'] ?? [])) >= 6
            && count((array) ($case['must_not_do'] ?? [])) >= 4
            && (string) ($case['expected_behavior'] ?? '') === 'fail_closed_emit_review_packet_and_preserve_checkpoint'
            && strlen((string) ($case['case_hash'] ?? '')) === 64));
        $readyStateAssertions = count(array_filter($stateAssertions, static fn (array $assertion): bool => count((array) ($assertion['state_objects'] ?? [])) >= 5
            && count((array) ($assertion['assertions'] ?? [])) >= 4
            && (string) ($assertion['primary_metric'] ?? '') !== ''
            && (string) ($assertion['primary_work_product'] ?? '') !== ''
            && strlen((string) ($assertion['assertion_hash'] ?? '')) === 64));
        $readyReplayMatrix = count(array_filter($replayMatrix, static fn (array $matrix): bool => count((array) ($matrix['replay_modes'] ?? [])) >= 4
            && count((array) ($matrix['comparison_dimensions'] ?? [])) >= 7
            && count((array) ($matrix['required_artifacts'] ?? [])) >= 6
            && (string) ($matrix['regression_action'] ?? '') === 'block_promotion_open_flow_quality_review_and_attach_replay_diff'
            && strlen((string) ($matrix['replay_hash'] ?? '')) === 64));
        $runtimeCompletedFlowCount = (int) ($runtimeRow['completed_benchmark_replay_flow_count'] ?? 0);

        $checks = [
            'benchmark_stack_schema_green' => (string) ($stack['schema'] ?? '') === 'atlas.ai.company.enterprise_flow_benchmark_replay_stack.v1',
            'benchmark_stack_hash_green' => strlen((string) ($stack['benchmark_replay_hash'] ?? '')) === 64,
            'offline_dataset_contracts_cover_flows' => $flowCount > 0 && count($offlineDatasets) >= $flowCount && $readyOfflineDatasets >= $flowCount,
            'trace_grading_rubrics_cover_flows' => $flowCount > 0 && count($traceRubrics) >= $flowCount && $readyTraceRubrics >= $flowCount,
            'adversarial_regression_cases_cover_flows' => $flowCount > 0 && count($adversarialCases) >= $flowCount && $readyAdversarialCases >= $flowCount,
            'deterministic_state_assertions_cover_flows' => $flowCount > 0 && count($stateAssertions) >= $flowCount && $readyStateAssertions >= $flowCount,
            'replay_comparison_matrix_covers_flows' => $flowCount > 0 && count($replayMatrix) >= $flowCount && $readyReplayMatrix >= $flowCount,
            'promotion_quality_gates_green' => (float) data_get($stack, 'promotion_quality_gates.minimum_offline_eval_score', 0.0) >= 0.86
                && (float) data_get($stack, 'promotion_quality_gates.minimum_trace_grade_score', 0.0) >= 0.86
                && (int) data_get($stack, 'promotion_quality_gates.policy_findings_allowed', 1) === 0
                && count((array) data_get($stack, 'promotion_quality_gates.required_green_replays', [])) >= 3
                && (bool) data_get($stack, 'promotion_quality_gates.operator_review_required_before_shadow_mode', false)
                && (bool) data_get($stack, 'promotion_quality_gates.observed_online_runs_required_before_autonomy_claim', false),
            'observability_green' => count($observabilityMetrics) >= 6,
            'runtime_or_structural_coverage_green' => $runtimeCompletedFlowCount >= $flowCount || $readyReplayMatrix >= $flowCount,
            'synthetic_score_claims_blocked' => (bool) data_get($stack, 'benchmark_policy.synthetic_score_claims_allowed', true) === false,
            'promotion_without_replay_green_blocked' => (bool) data_get($stack, 'benchmark_policy.promotion_without_replay_green_allowed', true) === false,
            'external_paid_benchmark_requires_operator_approval' => (bool) data_get($stack, 'benchmark_policy.external_model_or_paid_benchmark_requires_operator_approval', false),
        ];

        $row = [
            'schema' => 'atlas.ai.company.flow_benchmark_replay_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'offline_dataset_contract_count' => count($offlineDatasets),
            'ready_offline_dataset_contract_count' => $readyOfflineDatasets,
            'trace_grading_rubric_count' => count($traceRubrics),
            'ready_trace_grading_rubric_count' => $readyTraceRubrics,
            'adversarial_regression_case_count' => count($adversarialCases),
            'ready_adversarial_regression_case_count' => $readyAdversarialCases,
            'deterministic_state_assertion_count' => count($stateAssertions),
            'ready_deterministic_state_assertion_count' => $readyStateAssertions,
            'replay_comparison_matrix_count' => count($replayMatrix),
            'ready_replay_comparison_matrix_count' => $readyReplayMatrix,
            'benchmark_observability_metric_count' => count($observabilityMetrics),
            'runtime_completed_benchmark_flow_count' => $runtimeCompletedFlowCount,
            'required_benchmark_surfaces' => ['offline_dataset_contract', 'trace_grading_rubric', 'adversarial_regression_case', 'deterministic_state_assertion', 'replay_comparison_matrix', 'benchmark_observability'],
            'next_actions' => ['run_offline_eval', 'grade_trace', 'run_adversarial_regression', 'assert_deterministic_state', 'compare_replay_matrix', 'block_promotion_on_regression'],
            'external_benchmark_execution_allowed' => false,
            'synthetic_score_claims_allowed' => false,
            'promotion_without_replay_green_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['flow_benchmark_replay_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $runtimeRow
     * @return array<string,mixed>
     */
    public function connectorCertificationPreflightCompany(array $company, array $runtimeRow): array
    {
        $certification = (array) data_get($company, 'enterprise_connector_certification_stack', []);
        $preflight = (array) data_get($company, 'enterprise_production_connector_preflight_stack', []);
        $flowCount = count((array) ($company['flows'] ?? []));
        $connectorCount = count((array) ($company['connectors'] ?? []));
        $adapterContracts = (array) data_get($certification, 'adapter_contract_catalog', []);
        $authBoundaries = (array) data_get($certification, 'auth_and_secret_boundary', []);
        $sandboxProbes = (array) data_get($certification, 'sandbox_probe_matrix', []);
        $contractTests = (array) data_get($certification, 'consumer_provider_contract_tests', []);
        $dataLineage = (array) data_get($certification, 'connector_data_mapping_and_lineage', []);
        $flowUsage = (array) data_get($certification, 'flow_connector_usage_matrix', []);
        $replayFixtures = (array) data_get($certification, 'replay_fixture_and_mock_server_plan', []);
        $sloFailureModes = (array) data_get($certification, 'connector_slo_and_failure_mode_catalog', []);
        $certificationMetrics = (array) data_get($certification, 'connector_certification_observability.required_metrics', []);
        $productionContracts = (array) data_get($preflight, 'connector_preflight_contracts', []);
        $cutoverMatrix = (array) data_get($preflight, 'flow_connector_cutover_matrix', []);
        $evidenceRegister = (array) data_get($preflight, 'production_readiness_evidence_register', []);
        $cutoverMetrics = (array) data_get($preflight, 'cutover_observability.required_metrics', []);

        $readyAdapterContracts = count(array_filter($adapterContracts, static fn (array $contract): bool => count((array) ($contract['supported_contract_forms'] ?? [])) >= 4
            && count((array) ($contract['required_contract_fields'] ?? [])) >= 6
            && (bool) ($contract['schema_validation_required'] ?? false)
            && strlen((string) ($contract['contract_hash'] ?? '')) === 64));
        $readyAuthBoundaries = count(array_filter($authBoundaries, static fn (array $boundary): bool => (string) ($boundary['credential_binding'] ?? '') === 'vault_reference_only'
            && (string) ($boundary['minimum_scope'] ?? '') === 'read_or_internal_probe'
            && count((array) ($boundary['token_policy'] ?? [])) >= 4
            && count((array) ($boundary['blocked_scope_expansions_without_operator'] ?? [])) >= 7
            && (bool) ($boundary['secret_material_in_packet_allowed'] ?? true) === false
            && strlen((string) ($boundary['auth_hash'] ?? '')) === 64));
        $readySandboxProbes = count(array_filter($sandboxProbes, static fn (array $probe): bool => count((array) ($probe['probe_modes'] ?? [])) >= 5
            && count((array) ($probe['success_criteria'] ?? [])) >= 5
            && (string) ($probe['failure_action'] ?? '') === 'block_connector_and_open_integration_review'
            && strlen((string) ($probe['probe_hash'] ?? '')) === 64));
        $readyContractTests = count(array_filter($contractTests, static fn (array $test): bool => count((array) ($test['consumer_assumptions'] ?? [])) >= 4
            && count((array) ($test['provider_verification'] ?? [])) >= 4
            && (string) ($test['deployment_gate'] ?? '') === 'cannot_promote_connector_until_contract_verified'
            && strlen((string) ($test['test_hash'] ?? '')) === 64));
        $readyDataLineage = count(array_filter($dataLineage, static fn (array $mapping): bool => count((array) ($mapping['canonical_entities'] ?? [])) >= 5
            && count((array) ($mapping['lineage_required'] ?? [])) >= 5
            && (bool) ($mapping['redaction_required_before_provider_payload'] ?? false)
            && strlen((string) ($mapping['mapping_hash'] ?? '')) === 64));
        $readyFlowUsage = count(array_filter($flowUsage, static fn (array $usage): bool => count((array) ($usage['connectors'] ?? [])) >= 1
            && count((array) ($usage['allowed_modes'] ?? [])) >= 3
            && count((array) ($usage['blocked_modes'] ?? [])) >= 5
            && count((array) ($usage['pre_run_requirements'] ?? [])) >= 4
            && strlen((string) ($usage['usage_hash'] ?? '')) === 64));
        $readyReplayFixtures = count(array_filter($replayFixtures, static fn (array $fixture): bool => count((array) ($fixture['fixture_requirements'] ?? [])) >= 5
            && count((array) ($fixture['mock_or_stub_modes'] ?? [])) >= 3
            && (bool) ($fixture['required_for_offline_eval'] ?? false)
            && strlen((string) ($fixture['fixture_hash'] ?? '')) === 64));
        $readySloFailureModes = count(array_filter($sloFailureModes, static fn (array $slo): bool => count((array) ($slo['slo'] ?? [])) >= 3
            && count((array) ($slo['failure_modes'] ?? [])) >= 6
            && (string) ($slo['fallback'] ?? '') !== ''
            && strlen((string) ($slo['slo_hash'] ?? '')) === 64));
        $readyProductionContracts = count(array_filter($productionContracts, static fn (array $contract): bool => (string) ($contract['schema'] ?? '') === 'atlas.ai.company.connector_production_preflight_contract.v1'
            && (bool) data_get($contract, 'credential_vault_binding.attestation_required', false)
            && (bool) data_get($contract, 'credential_vault_binding.credential_material_in_packet_allowed', true) === false
            && (bool) data_get($contract, 'scope_contract.production_scope_requires_operator_and_second_reviewer', false)
            && count((array) data_get($contract, 'scope_contract.blocked_scope_without_signed_mandate', [])) >= 8
            && (bool) data_get($contract, 'live_data_readiness.live_mutation_allowed', true) === false
            && (bool) data_get($contract, 'non_production_dress_rehearsal.required', false)
            && (bool) data_get($contract, 'cost_and_rate_limit_envelope.spend_without_cap_allowed', true) === false
            && (bool) data_get($contract, 'rollback_and_fallback.rollback_drill_required_before_external_mutation', false)
            && strlen((string) ($contract['preflight_hash'] ?? '')) === 64));
        $readyCutovers = count(array_filter($cutoverMatrix, static fn (array $cutover): bool => count((array) ($cutover['connector_scope'] ?? [])) >= 1
            && count((array) ($cutover['required_cutover_evidence'] ?? [])) >= 6
            && (bool) ($cutover['manual_handoff_packet_required'] ?? false)
            && (bool) ($cutover['auto_execute_allowed'] ?? true) === false
            && (bool) ($cutover['external_side_effects_enabled'] ?? true) === false
            && strlen((string) ($cutover['cutover_hash'] ?? '')) === 64));
        $readyEvidence = count(array_filter($evidenceRegister, static fn (array $evidence): bool => count((array) ($evidence['required_evidence'] ?? [])) >= 8
            && (string) ($evidence['current_state'] ?? '') === 'preflight_contract_ready_external_execution_blocked'
            && count((array) ($evidence['missing_before_real_execution'] ?? [])) >= 3
            && (bool) ($evidence['external_side_effects_enabled'] ?? true) === false
            && strlen((string) ($evidence['evidence_register_hash'] ?? '')) === 64));
        $runtimeCompletedFlowCount = (int) ($runtimeRow['completed_connector_certification_preflight_flow_count'] ?? 0);

        $checks = [
            'connector_certification_schema_green' => (string) ($certification['schema'] ?? '') === 'atlas.ai.company.enterprise_connector_certification_stack.v1',
            'connector_certification_hash_green' => strlen((string) ($certification['connector_certification_hash'] ?? '')) === 64,
            'production_preflight_schema_green' => (string) ($preflight['schema'] ?? '') === 'atlas.ai.company.enterprise_production_connector_preflight_stack.v1',
            'production_preflight_hash_green' => strlen((string) ($preflight['production_connector_preflight_hash'] ?? '')) === 64,
            'adapter_contracts_cover_connectors' => $connectorCount > 0 && count($adapterContracts) >= $connectorCount && $readyAdapterContracts >= $connectorCount,
            'auth_boundaries_cover_connectors' => $connectorCount > 0 && count($authBoundaries) >= $connectorCount && $readyAuthBoundaries >= $connectorCount,
            'sandbox_probes_cover_connectors' => $connectorCount > 0 && count($sandboxProbes) >= $connectorCount && $readySandboxProbes >= $connectorCount,
            'contract_tests_cover_connectors' => $connectorCount > 0 && count($contractTests) >= $connectorCount && $readyContractTests >= $connectorCount,
            'data_lineage_covers_connectors' => $connectorCount > 0 && count($dataLineage) >= $connectorCount && $readyDataLineage >= $connectorCount,
            'flow_usage_covers_flows' => $flowCount > 0 && count($flowUsage) >= $flowCount && $readyFlowUsage >= $flowCount,
            'replay_fixtures_cover_connectors' => $connectorCount > 0 && count($replayFixtures) >= $connectorCount && $readyReplayFixtures >= $connectorCount,
            'slo_failure_modes_cover_connectors' => $connectorCount > 0 && count($sloFailureModes) >= $connectorCount && $readySloFailureModes >= $connectorCount,
            'certification_observability_green' => count($certificationMetrics) >= 6,
            'production_contracts_cover_connectors' => $connectorCount > 0 && count($productionContracts) >= $connectorCount && $readyProductionContracts >= $connectorCount,
            'flow_cutover_matrix_covers_flows' => $flowCount > 0 && count($cutoverMatrix) >= $flowCount && $readyCutovers >= $flowCount,
            'production_evidence_covers_connectors' => $connectorCount > 0 && count($evidenceRegister) >= $connectorCount && $readyEvidence >= $connectorCount,
            'cutover_observability_green' => count($cutoverMetrics) >= 6,
            'runtime_or_structural_coverage_green' => $runtimeCompletedFlowCount >= $flowCount || ($readyFlowUsage >= $flowCount && $readyCutovers >= $flowCount),
            'write_or_paid_mode_blocked_by_default' => (bool) data_get($certification, 'certification_policy.write_or_paid_mode_allowed_by_default', true) === false,
            'production_promotion_requires_green_probe' => (bool) data_get($certification, 'certification_policy.production_promotion_without_green_probe_allowed', true) === false,
            'operator_approval_required_for_scope_expansion' => (bool) data_get($certification, 'certification_policy.operator_approval_required_for_write_publish_spend_trade_delete_or_secret_scope_expansion', false),
            'calendar_wait_removed' => (bool) data_get($preflight, 'preflight_policy.calendar_wait_blocker_enabled', true) === false,
            'operator_signed_scope_required' => (bool) data_get($preflight, 'preflight_policy.production_cutover_without_operator_signed_scope_allowed', true) === false,
            'real_credential_material_blocked' => (bool) data_get($preflight, 'preflight_policy.real_credential_material_in_packet_allowed', true) === false,
            'external_side_effects_blocked' => (bool) data_get($preflight, 'preflight_policy.external_side_effects_default', true) === false,
            'manual_execution_handoff_only' => (bool) data_get($preflight, 'preflight_policy.manual_execution_handoff_only_after_signed_mandate', false),
        ];

        $row = [
            'schema' => 'atlas.ai.company.connector_certification_preflight_status.v1',
            'company_id' => (string) ($company['company_id'] ?? 'unknown'),
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'flow_count' => $flowCount,
            'connector_count' => $connectorCount,
            'adapter_contract_count' => count($adapterContracts),
            'auth_boundary_count' => count($authBoundaries),
            'sandbox_probe_count' => count($sandboxProbes),
            'contract_test_count' => count($contractTests),
            'data_lineage_count' => count($dataLineage),
            'flow_connector_usage_count' => count($flowUsage),
            'replay_fixture_count' => count($replayFixtures),
            'slo_failure_mode_count' => count($sloFailureModes),
            'certification_metric_count' => count($certificationMetrics),
            'production_preflight_contract_count' => count($productionContracts),
            'flow_cutover_matrix_count' => count($cutoverMatrix),
            'production_evidence_register_count' => count($evidenceRegister),
            'cutover_metric_count' => count($cutoverMetrics),
            'runtime_completed_connector_flow_count' => $runtimeCompletedFlowCount,
            'next_actions' => ['run_contract_tests', 'run_sandbox_probes', 'attest_vault_boundaries', 'verify_lineage_and_replay_fixtures', 'collect_operator_signed_scope_before_cutover'],
            'external_connector_cutover_allowed' => false,
            'write_or_paid_mode_allowed_by_default' => false,
            'real_credential_material_in_packet_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['connector_certification_preflight_company_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }
}
