<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Services\Ai\AtlasDomainProfileRegistry;
use App\Services\Ai\Kernel\Capability\AtlasCapabilityRegistry;
use App\Services\Ai\Kernel\Capability\SurfaceCapabilityParityService;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Domain\AtlasDomainManifestValidator;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestratorRegistry;
use App\Services\Ai\Kernel\Evidence\LedgerProjectionRegistry;
use App\Services\Ai\Kernel\Failure\FailureClassifier;
use App\Services\Ai\Kernel\Failure\FailureHandlerRegistry;
use App\Services\Ai\Kernel\Provider\ProviderDriver;
use App\Services\Ai\Kernel\Slo\KernelSloTargets;
use App\Services\Ai\Kernel\Surface\SurfaceAdapter;
use App\Services\Ai\Provider\Drivers\ProviderDriverRegistry;
use App\Services\Ai\Surface\SurfaceAdapterRegistry;
use App\Services\Engineering\EngineeringDocumentationHealthService;

class AtlasAiArchitectureValidationService
{
    public function __construct(
        private readonly AtlasCapabilityRegistry $capabilities,
        private readonly AtlasDomainProfileRegistry $profiles,
        private readonly AtlasDomainManifestValidator $domains,
        private readonly AtlasDomainOrchestratorRegistry $orchestrators,
        private readonly AtlasAiDomainCatalogService $domainCatalog,
        private readonly FailureClassifier $failureClassifier,
        private readonly FailureHandlerRegistry $failureHandlers,
        private readonly KernelSloTargets $sloTargets,
        private readonly SurfaceAdapterRegistry $surfaceAdapters,
        private readonly ProviderDriverRegistry $providerDrivers,
        private readonly KernelArchitectureStaticScanner $staticScanner,
        private readonly SurfaceCapabilityParityService $surfaceCapabilityParity,
        private readonly LedgerProjectionRegistry $ledgerProjections,
        private readonly EngineeringDocumentationHealthService $documentationHealth,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        $capabilityReport = $this->capabilities->complianceReport();
        $surfaceCapabilityReport = $this->surfaceCapabilityParity->complianceReport();
        $orchestratorReport = $this->orchestrators->complianceReport();
        $catalog = $this->profiles->catalog();
        $domainReport = $this->domains->validateCatalog($catalog);
        $domainCatalogReport = $this->domainCatalog->inspect();
        $failureClassifierReport = $this->failureClassifier->complianceReport();
        $failureReport = $this->failureHandlers->complianceReport();
        $sloReport = $this->sloTargets->complianceReport();
        $surfaceReport = $this->surfaceAdapters->complianceReport();
        $providerReport = $this->providerDrivers->complianceReport();
        $ledgerProjectionReport = $this->ledgerProjections->complianceReport();
        $ledgerProjectionDriftReport = $this->ledgerProjections->driftReport();
        $documentationHealthReport = $this->documentationHealth->report();
        $staticScanReport = $this->staticScanner->complianceReport();
        $kernelContracts = [
            'surface_adapter' => interface_exists(SurfaceAdapter::class),
            'provider_driver' => interface_exists(ProviderDriver::class),
        ];
        $surfaceCapabilityParityValid = $surfaceCapabilityReport['ok'];
        $capabilitySurfaceCoverageValid = (bool) data_get($capabilityReport, 'surface_coverage.valid', false);
        $staticScanValid = $staticScanReport['ok'] && $surfaceCapabilityParityValid && $capabilitySurfaceCoverageValid;
        $staticScanPayload = $this->staticScanPayload($staticScanReport, $staticScanValid, $surfaceCapabilityReport, $capabilitySurfaceCoverageValid, $capabilityReport);
        $kernelValid = $failureClassifierReport['ok']
            && $failureReport['ok']
            && $sloReport['ok']
            && $surfaceReport['ok']
            && $providerReport['ok']
            && $ledgerProjectionReport['ok']
            && $staticScanValid
            && $kernelContracts['surface_adapter']
            && $kernelContracts['provider_driver'];

        $capabilitiesValid = $capabilityReport['valid'] && $surfaceCapabilityParityValid;
        $documentationHealthValid = ($documentationHealthReport['status'] ?? 'failed') === 'ok';

        return [
            'schema_version' => 1,
            'status' => $capabilitiesValid && $orchestratorReport['ok'] && $domainReport['ok'] && $kernelValid && $documentationHealthValid ? 'ok' : 'failed',
            'kernel' => [
                'valid' => $kernelValid,
                'surface_adapter_contract' => $kernelContracts['surface_adapter'],
                'provider_driver_contract' => $kernelContracts['provider_driver'],
                'failure_domains' => [
                    'valid' => $failureReport['ok'],
                    'count' => $failureReport['count'],
                    'missing_handlers' => $failureReport['missing'],
                ],
                'failure_classifier' => [
                    'valid' => $failureClassifierReport['ok'],
                    'rule_count' => $failureClassifierReport['rule_count'],
                    'status_code_count' => $failureClassifierReport['status_code_count'],
                    'missing_domains' => $failureClassifierReport['missing_domains'],
                    'duplicate_signals' => $failureClassifierReport['duplicate_signals'],
                ],
                'slo_targets' => [
                    'valid' => $sloReport['ok'],
                    'count' => $sloReport['count'],
                    'schema_version' => $sloReport['schema_version'],
                    'stages' => $sloReport['stages'],
                    'errors' => $sloReport['errors'],
                ],
                'surface_adapters' => [
                    'valid' => $surfaceReport['ok'],
                    'count' => $surfaceReport['count'],
                    'surfaces' => $surfaceReport['surfaces'],
                    'aliases' => $surfaceReport['aliases'],
                    'errors' => $surfaceReport['errors'],
                ],
                'provider_drivers' => [
                    'valid' => $providerReport['ok'],
                    'count' => $providerReport['count'],
                    'providers' => $providerReport['providers'],
                    'errors' => $providerReport['errors'],
                    'warnings' => $providerReport['warnings'],
                ],
                'ledger_projections' => [
                    'valid' => $ledgerProjectionReport['ok'],
                    'ready' => $ledgerProjectionReport['ready'],
                    'count' => $ledgerProjectionReport['count'],
                    'ready_count' => $ledgerProjectionReport['ready_count'],
                    'schema_version' => $ledgerProjectionReport['schema_version'],
                    'projection_ids' => $ledgerProjectionReport['projection_ids'],
                    'errors' => $ledgerProjectionReport['errors'],
                    'warnings' => $ledgerProjectionReport['warnings'],
                    'projections' => $ledgerProjectionReport['projections'],
                    'drift' => $ledgerProjectionDriftReport,
                ],
                'static_scan' => $staticScanPayload,
            ],
            'capabilities' => [
                'valid' => $capabilitiesValid,
                'count' => $this->capabilities->all()->count(),
                'surface_count' => count($this->capabilities->surfaces()),
                'surface_adapter_parity' => [
                    'valid' => $surfaceCapabilityReport['ok'],
                    'checked' => $surfaceCapabilityReport['checked'],
                    'errors' => $surfaceCapabilityReport['errors'],
                    'skipped' => $surfaceCapabilityReport['skipped'],
                    'mapped_adapters' => $surfaceCapabilityReport['mapped_adapters'],
                    'unmapped_adapters' => $surfaceCapabilityReport['unmapped_adapters'],
                ],
                'errors' => $capabilityReport['errors'],
                'warnings' => $capabilityReport['warnings'],
                'surface_coverage' => $capabilityReport['surface_coverage'],
            ],
            'orchestrators' => [
                'valid' => $orchestratorReport['ok'],
                'count' => $orchestratorReport['orchestrators'],
                'errors' => $orchestratorReport['errors'],
                'warnings' => $orchestratorReport['warnings'],
            ],
            'domains' => [
                'valid' => $domainReport['ok'],
                'source' => (string) ($catalog['source'] ?? 'unknown'),
                'domain_count' => $domainReport['domains'],
                'flow_count' => $domainReport['flows'],
                'errors' => $domainReport['errors'],
                'warnings' => $domainReport['warnings'],
            ],
            'onboarding' => [
                'ready_domains' => (int) data_get($domainCatalogReport, 'summary.ready_domains', 0),
                'executable_incomplete_domains' => (int) data_get($domainCatalogReport, 'summary.executable_incomplete_domains', 0),
                'scaffold_domains' => (int) data_get($domainCatalogReport, 'summary.scaffold_domains', 0),
                'status_counts' => (array) data_get($domainCatalogReport, 'summary.onboarding_status_counts', []),
                'domain_count' => (int) data_get($domainCatalogReport, 'summary.domains', 0),
            ],
            'documentation' => [
                'valid' => $documentationHealthValid,
                'status' => (string) ($documentationHealthReport['status'] ?? 'failed'),
                'summary' => (array) ($documentationHealthReport['summary'] ?? []),
                'required_docs' => (array) ($documentationHealthReport['required_docs'] ?? []),
                'oversized_docs' => (array) ($documentationHealthReport['oversized_docs'] ?? []),
                'violations' => (array) ($documentationHealthReport['violations'] ?? []),
            ],
            'validated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $staticScanReport
     * @param  array<string,mixed>  $surfaceCapabilityReport
     * @param  array<string,mixed>  $capabilityReport
     * @return array<string,mixed>
     */
    private function staticScanPayload(
        array $staticScanReport,
        bool $staticScanValid,
        array $surfaceCapabilityReport,
        bool $capabilitySurfaceCoverageValid,
        array $capabilityReport,
    ): array {
        $payload = [
            'valid' => $staticScanValid,
            'ap1_surface_provider_bypass' => $staticScanReport['ap1_surface_provider_bypass'],
            'ap2_surface_context_bypass' => $staticScanReport['ap2_surface_context_bypass'],
            'ap6_decision_receipt_propagation' => $staticScanReport['ap6_decision_receipt_propagation'],
            'ap13_decision_receipt_runtime_guard' => $staticScanReport['ap13_decision_receipt_runtime_guard'],
            'ap134_decision_receipt_hash_runtime_guard' => $staticScanReport['ap134_decision_receipt_hash_runtime_guard'],
            'ap135_decision_receipt_determinism_test' => $staticScanReport['ap135_decision_receipt_determinism_test'],
            'ap136_decision_receipt_chain_replay' => $staticScanReport['ap136_decision_receipt_chain_replay'],
            'ap137_decision_receipt_replay_surfaces' => $staticScanReport['ap137_decision_receipt_replay_surfaces'],
            'ap138_decision_receipt_replay_curator_review' => $staticScanReport['ap138_decision_receipt_replay_curator_review'],
            'ap139_decision_receipt_replay_inbox_emission' => $staticScanReport['ap139_decision_receipt_replay_inbox_emission'],
            'ap140_ledger_replay_command_surface' => $staticScanReport['ap140_ledger_replay_command_surface'],
            'ap141_ledger_projection_registry_contract' => $staticScanReport['ap141_ledger_projection_registry_contract'],
            'ap142_ledger_projection_inbox_action' => $staticScanReport['ap142_ledger_projection_inbox_action'],
            'ap143_ledger_projection_curator_action_emission' => $staticScanReport['ap143_ledger_projection_curator_action_emission'],
            'ap12_provider_driver_identity_bypass' => $staticScanReport['ap12_provider_driver_identity_bypass'],
            'ap14_tool_tier_hot_path' => $staticScanReport['ap14_tool_tier_hot_path'],
            'ap15_provider_memory_privacy' => $staticScanReport['ap15_provider_memory_privacy'],
            'ap16_slo_observability' => $staticScanReport['ap16_slo_observability'],
            'ap17_kernel_pipeline_contract' => $staticScanReport['ap17_kernel_pipeline_contract'],
            'ap18_repair_loop_contract' => $staticScanReport['ap18_repair_loop_contract'],
            'ap19_mcp_domain_catalog_parity' => $staticScanReport['ap19_mcp_domain_catalog_parity'],
            'ap20_cli_fix_dev_repair_alias' => $staticScanReport['ap20_cli_fix_dev_repair_alias'],
            'ap21_cli_continue_dev_resume_alias' => $staticScanReport['ap21_cli_continue_dev_resume_alias'],
            'ap22_cli_forge_programming_harness_contract' => $staticScanReport['ap22_cli_forge_programming_harness_contract'],
            'ap23_chat_dev_programming_contract' => $staticScanReport['ap23_chat_dev_programming_contract'],
            'ap24_surface_alias_canonicalization' => $staticScanReport['ap24_surface_alias_canonicalization'],
            'ap25_decide_model_selection_contract' => $staticScanReport['ap25_decide_model_selection_contract'],
            'ap26_cli_dev_model_selection_contract' => $staticScanReport['ap26_cli_dev_model_selection_contract'],
            'ap27_chat_model_selection_contract' => $staticScanReport['ap27_chat_model_selection_contract'],
            'ap28_kernel_model_selection_contract_factory' => $staticScanReport['ap28_kernel_model_selection_contract_factory'],
            'ap29_programming_surface_contract_factory' => $staticScanReport['ap29_programming_surface_contract_factory'],
            'ap30_chat_programming_contract_factory' => $staticScanReport['ap30_chat_programming_contract_factory'],
            'ap31_continue_resume_contract_factory' => $staticScanReport['ap31_continue_resume_contract_factory'],
            'ap32_fix_contract_factory' => $staticScanReport['ap32_fix_contract_factory'],
            'ap36_kernel_pipeline_health_read_model' => $staticScanReport['ap36_kernel_pipeline_health_read_model'],
            'ap37_architecture_validation_surface' => $staticScanReport['ap37_architecture_validation_surface'],
            'ap38_architecture_validation_observability' => $staticScanReport['ap38_architecture_validation_observability'],
            'ap39_architecture_validation_contract_parity' => $staticScanReport['ap39_architecture_validation_contract_parity'],
            'ap40_architecture_validation_mcp_tool' => $staticScanReport['ap40_architecture_validation_mcp_tool'],
            'ap41_self_improvement_architecture_validation_review' => $staticScanReport['ap41_self_improvement_architecture_validation_review'],
            'ap42_self_improvement_architecture_audit_schedule' => $staticScanReport['ap42_self_improvement_architecture_audit_schedule'],
            'ap43_self_improvement_flow_cadence_contract' => $staticScanReport['ap43_self_improvement_flow_cadence_contract'],
            'ap44_self_improvement_command_next_run_contract' => $staticScanReport['ap44_self_improvement_command_next_run_contract'],
            'ap45_self_improvement_schedule_mcp_tool' => $staticScanReport['ap45_self_improvement_schedule_mcp_tool'],
            'ap46_self_improvement_schedule_health_review' => $staticScanReport['ap46_self_improvement_schedule_health_review'],
            'ap47_self_improvement_schedule_health_ledger_event' => $staticScanReport['ap47_self_improvement_schedule_health_ledger_event'],
            'ap48_self_improvement_schedule_replay_read_model' => $staticScanReport['ap48_self_improvement_schedule_replay_read_model'],
            'ap49_self_improvement_schedule_replay_surfaces' => $staticScanReport['ap49_self_improvement_schedule_replay_surfaces'],
            'ap50_self_improvement_schedule_replay_mcp_tool' => $staticScanReport['ap50_self_improvement_schedule_replay_mcp_tool'],
            'ap51_self_improvement_schedule_replay_review' => $staticScanReport['ap51_self_improvement_schedule_replay_review'],
            'ap52_self_improvement_schedule_replay_review_signal' => $staticScanReport['ap52_self_improvement_schedule_replay_review_signal'],
            'ap53_self_improvement_schedule_replay_review_signal_surfaces' => $staticScanReport['ap53_self_improvement_schedule_replay_review_signal_surfaces'],
            'ap54_kernel_pipeline_review_signal' => $staticScanReport['ap54_kernel_pipeline_review_signal'],
            'ap55_repair_loop_review_signal' => $staticScanReport['ap55_repair_loop_review_signal'],
            'ap56_slo_review_signal' => $staticScanReport['ap56_slo_review_signal'],
            'ap57_slo_mcp_tool' => $staticScanReport['ap57_slo_mcp_tool'],
            'ap58_kernel_pipeline_mcp_tool' => $staticScanReport['ap58_kernel_pipeline_mcp_tool'],
            'ap59_repair_loop_mcp_tool' => $staticScanReport['ap59_repair_loop_mcp_tool'],
            'ap60_repair_loop_unavailable_review_signal' => $staticScanReport['ap60_repair_loop_unavailable_review_signal'],
            'ap61_slo_unavailable_review_signal' => $staticScanReport['ap61_slo_unavailable_review_signal'],
            'ap62_mcp_replay_unavailable_review_signal' => $staticScanReport['ap62_mcp_replay_unavailable_review_signal'],
            'ap63_schedule_replay_unavailable_shape_parity' => $staticScanReport['ap63_schedule_replay_unavailable_shape_parity'],
            'ap64_mcp_replay_window_contract' => $staticScanReport['ap64_mcp_replay_window_contract'],
            'ap65_mcp_replay_filter_contract' => $staticScanReport['ap65_mcp_replay_filter_contract'],
            'ap66_replay_report_input_contract' => $staticScanReport['ap66_replay_report_input_contract'],
            'ap67_observability_replay_input_contract' => $staticScanReport['ap67_observability_replay_input_contract'],
            'ap68_replay_report_validation_limit_contract' => $staticScanReport['ap68_replay_report_validation_limit_contract'],
            'ap69_self_improvement_runtime_window_contract' => $staticScanReport['ap69_self_improvement_runtime_window_contract'],
            'ap70_self_improvement_schedule_window_contract' => $staticScanReport['ap70_self_improvement_schedule_window_contract'],
            'ap71_self_improvement_orchestrator_window_contract' => $staticScanReport['ap71_self_improvement_orchestrator_window_contract'],
            'ap72_telemetry_window_input_contract' => $staticScanReport['ap72_telemetry_window_input_contract'],
            'ap73_runtime_budget_window_contract' => $staticScanReport['ap73_runtime_budget_window_contract'],
            'ap74_ledger_envelope_input_contract' => $staticScanReport['ap74_ledger_envelope_input_contract'],
            'ap75_ledger_envelope_report_contract' => $staticScanReport['ap75_ledger_envelope_report_contract'],
            'ap76_telemetry_list_limit_contract' => $staticScanReport['ap76_telemetry_list_limit_contract'],
            'ap77_programming_iteration_policy_contract' => $staticScanReport['ap77_programming_iteration_policy_contract'],
            'ap78_open_brain_mcp_input_contract' => $staticScanReport['ap78_open_brain_mcp_input_contract'],
            'ap79_memory_query_input_contract' => $staticScanReport['ap79_memory_query_input_contract'],
            'ap80_provider_projection_audit_input_contract' => $staticScanReport['ap80_provider_projection_audit_input_contract'],
            'ap81_conversation_context_input_contract' => $staticScanReport['ap81_conversation_context_input_contract'],
            'ap82_retrieval_rank_input_contract' => $staticScanReport['ap82_retrieval_rank_input_contract'],
            'ap83_atlas_vault_command_input_contract' => $staticScanReport['ap83_atlas_vault_command_input_contract'],
            'ap84_memory_recall_input_contract' => $staticScanReport['ap84_memory_recall_input_contract'],
            'ap85_context_pack_memory_input_contract' => $staticScanReport['ap85_context_pack_memory_input_contract'],
            'ap86_semantic_context_input_contract' => $staticScanReport['ap86_semantic_context_input_contract'],
            'ap87_provider_projection_input_contract' => $staticScanReport['ap87_provider_projection_input_contract'],
            'ap88_test_command_input_contract' => $staticScanReport['ap88_test_command_input_contract'],
            'ap89_engineering_harness_runner_input_contract' => $staticScanReport['ap89_engineering_harness_runner_input_contract'],
            'ap90_engineering_harnessability_input_contract' => $staticScanReport['ap90_engineering_harnessability_input_contract'],
            'ap91_engineering_docker_harness_input_contract' => $staticScanReport['ap91_engineering_docker_harness_input_contract'],
            'ap92_engineering_test_matrix_input_contract' => $staticScanReport['ap92_engineering_test_matrix_input_contract'],
            'ap93_engineering_claude_code_baseline_input_contract' => $staticScanReport['ap93_engineering_claude_code_baseline_input_contract'],
            'ap94_engineering_benchmark_input_contract' => $staticScanReport['ap94_engineering_benchmark_input_contract'],
            'ap95_engineering_context_intelligence_input_contract' => $staticScanReport['ap95_engineering_context_intelligence_input_contract'],
            'ap96_cli_limit_input_contract' => $staticScanReport['ap96_cli_limit_input_contract'],
            'ap97_scheduler_input_contract' => $staticScanReport['ap97_scheduler_input_contract'],
            'ap98_self_improvement_input_contract' => $staticScanReport['ap98_self_improvement_input_contract'],
            'ap99_provider_usage_performance_contract' => $staticScanReport['ap99_provider_usage_performance_contract'],
            'ap100_context_pack_manifest_reflection_contract' => $staticScanReport['ap100_context_pack_manifest_reflection_contract'],
            'ap101_context_retrieval_router_contract' => $staticScanReport['ap101_context_retrieval_router_contract'],
            'ap102_open_brain_retrieval_plan_summary_contract' => $staticScanReport['ap102_open_brain_retrieval_plan_summary_contract'],
            'ap103_retrieval_required_source_availability_contract' => $staticScanReport['ap103_retrieval_required_source_availability_contract'],
            'ap104_retrieval_review_signal_next_action_contract' => $staticScanReport['ap104_retrieval_review_signal_next_action_contract'],
            'ap105_open_brain_retrieval_self_improvement_contract' => $staticScanReport['ap105_open_brain_retrieval_self_improvement_contract'],
            'ap106_learning_proposed_review_signal_projection_contract' => $staticScanReport['ap106_learning_proposed_review_signal_projection_contract'],
            'ap107_proposal_inbox_review_signal_contract' => $staticScanReport['ap107_proposal_inbox_review_signal_contract'],
            'ap108_learning_proposed_inbox_link_contract' => $staticScanReport['ap108_learning_proposed_inbox_link_contract'],
            'ap109_operation_completed_inbox_refs_contract' => $staticScanReport['ap109_operation_completed_inbox_refs_contract'],
            'ap110_schedule_replay_inbox_refs_contract' => $staticScanReport['ap110_schedule_replay_inbox_refs_contract'],
            'ap111_schedule_replay_inbox_refs_surface_parity' => $staticScanReport['ap111_schedule_replay_inbox_refs_surface_parity'],
            'ap112_schedule_replay_inbox_item_hydration' => $staticScanReport['ap112_schedule_replay_inbox_item_hydration'],
            'ap113_schedule_replay_inbox_item_hydration_surface_parity' => $staticScanReport['ap113_schedule_replay_inbox_item_hydration_surface_parity'],
            'ap114_schedule_replay_inbox_hydration_gap_signal' => $staticScanReport['ap114_schedule_replay_inbox_hydration_gap_signal'],
            'ap115_self_improvement_schedule_replay_inbox_gap_finding' => $staticScanReport['ap115_self_improvement_schedule_replay_inbox_gap_finding'],
            'ap116_self_improvement_schedule_replay_inbox_gap_emission' => $staticScanReport['ap116_self_improvement_schedule_replay_inbox_gap_emission'],
            'ap117_proposal_inbox_review_signal_severity' => $staticScanReport['ap117_proposal_inbox_review_signal_severity'],
            'ap118_proposal_review_action_contract' => $staticScanReport['ap118_proposal_review_action_contract'],
            'ap119_cli_inbox_review_action_result_parity' => $staticScanReport['ap119_cli_inbox_review_action_result_parity'],
            'ap120_inbox_action_evidence_ledger_contract' => $staticScanReport['ap120_inbox_action_evidence_ledger_contract'],
            'ap121_inbox_action_replay_read_model' => $staticScanReport['ap121_inbox_action_replay_read_model'],
            'ap122_inbox_action_mcp_report' => $staticScanReport['ap122_inbox_action_mcp_report'],
            'ap123_self_improvement_inbox_action_replay_review' => $staticScanReport['ap123_self_improvement_inbox_action_replay_review'],
            'ap124_observability_inbox_action_replay' => $staticScanReport['ap124_observability_inbox_action_replay'],
            'ap125_inbox_action_report_surfaces' => $staticScanReport['ap125_inbox_action_report_surfaces'],
            'ap126_architecture_validate_post_ap98_human_output' => $staticScanReport['ap126_architecture_validate_post_ap98_human_output'],
            'ap127_cli_help_architecture_operations_discovery' => $staticScanReport['ap127_cli_help_architecture_operations_discovery'],
            'ap128_architecture_operations_shared_catalog' => $staticScanReport['ap128_architecture_operations_shared_catalog'],
            'ap129_architecture_operations_mcp_tool' => $staticScanReport['ap129_architecture_operations_mcp_tool'],
            'ap130_architecture_operations_direct_surfaces' => $staticScanReport['ap130_architecture_operations_direct_surfaces'],
            'ap131_self_improvement_architecture_operations_review' => $staticScanReport['ap131_self_improvement_architecture_operations_review'],
            'ap132_architecture_operations_metadata_contract' => $staticScanReport['ap132_architecture_operations_metadata_contract'],
            'ap133_architecture_operations_filter_contract' => $staticScanReport['ap133_architecture_operations_filter_contract'],
            'ap33_surface_capability_parity' => [
                'valid' => $surfaceCapabilityReport['ok'],
                'checked' => $surfaceCapabilityReport['checked'],
                'violations' => array_values(array_merge(
                    $surfaceCapabilityReport['errors'],
                    $surfaceCapabilityReport['skipped'],
                )),
            ],
            'ap34_capability_surface_coverage' => [
                'valid' => $capabilitySurfaceCoverageValid,
                'violations' => (array) data_get($capabilityReport, 'surface_coverage.errors', []),
            ],
            'ap35_surface_adapter_parity_map_coverage' => [
                'valid' => $surfaceCapabilityReport['unmapped_adapters'] === [],
                'mapped_adapters' => $surfaceCapabilityReport['mapped_adapters'],
                'unmapped_adapters' => $surfaceCapabilityReport['unmapped_adapters'],
                'violations' => array_map(
                    fn (string $adapterId): string => "Surface adapter {$adapterId} is registered but missing from capability parity map.",
                    $surfaceCapabilityReport['unmapped_adapters'],
                ),
            ],
        ];
        $payload['summary'] = $this->staticScanSummary($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $staticScan
     * @return array{
     *     total_count:int,
     *     passed_count:int,
     *     failed_count:int,
     *     valid_keys:array<int,string>,
     *     failed_keys:array<int,string>,
     *     violation_count:int
     * }
     */
    private function staticScanSummary(array $staticScan): array
    {
        $checks = collect($staticScan)
            ->filter(fn (mixed $value, string $key): bool => str_starts_with($key, 'ap') && is_array($value));
        $failed = $checks
            ->filter(fn (array $check): bool => ! (bool) ($check['valid'] ?? false));

        return [
            'total_count' => $checks->count(),
            'passed_count' => $checks->count() - $failed->count(),
            'failed_count' => $failed->count(),
            'valid_keys' => $checks
                ->filter(fn (array $check): bool => (bool) ($check['valid'] ?? false))
                ->keys()
                ->values()
                ->all(),
            'failed_keys' => $failed
                ->keys()
                ->values()
                ->all(),
            'violation_count' => $checks
                ->sum(fn (array $check): int => count((array) ($check['violations'] ?? []))),
        ];
    }
}
