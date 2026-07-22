<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\Scanner\AgentBehaviorAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ArchitectureAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ArchitectureOperationsAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\CliAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\DecisionReceiptAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\EngineeringAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\InboxActionAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\KernelAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\LedgerAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\McpAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ProgrammingAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ProviderAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\RepairLoopAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ScanPrimitivesSupport;
use App\Services\Ai\Kernel\Architecture\Scanner\ScheduleReplayAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\SelfImprovementAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\SloAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\VoiceAudit;
use Illuminate\Support\Facades\File;

class KernelArchitectureStaticScanner
{
    public function __construct(
        private ScanPrimitivesSupport $primitives,
        private SelfImprovementAudit $selfImprovementAudit,
        private AgentBehaviorAudit $agentBehaviorAudit,
        private ArchitectureOperationsAudit $architectureOperationsAudit,
        private DecisionReceiptAudit $decisionReceiptAudit,
        private InboxActionAudit $inboxActionAudit,
        private ScheduleReplayAudit $scheduleReplayAudit,
        private RepairLoopAudit $repairLoopAudit,
        private EngineeringAudit $engineeringAudit,
        private CliAudit $cliAudit,
        private ProviderAudit $providerAudit,
        private LedgerAudit $ledgerAudit,
        private ArchitectureAudit $architectureAudit,
        private KernelAudit $kernelAudit,
        private VoiceAudit $voiceAudit,
        private SloAudit $sloAudit,
        private McpAudit $mcpAudit,
        private ProgrammingAudit $programmingAudit,
    ) {
    }

    /**
     * @return array{
     *   ok:bool,
     *   ap1_surface_provider_bypass:array{valid:bool,violations:array<int,string>},
     *   ap2_surface_context_bypass:array{valid:bool,violations:array<int,string>},
     *   ap6_decision_receipt_propagation:array{valid:bool,violations:array<int,string>},
     *   ap13_decision_receipt_runtime_guard:array{valid:bool,violations:array<int,string>},
     *   ap134_decision_receipt_hash_runtime_guard:array{valid:bool,violations:array<int,string>},
     *   ap135_decision_receipt_determinism_test:array{valid:bool,violations:array<int,string>},
     *   ap136_decision_receipt_chain_replay:array{valid:bool,violations:array<int,string>},
     *   ap137_decision_receipt_replay_surfaces:array{valid:bool,violations:array<int,string>},
     *   ap138_decision_receipt_replay_curator_review:array{valid:bool,violations:array<int,string>},
     *   ap139_decision_receipt_replay_inbox_emission:array{valid:bool,violations:array<int,string>},
     *   ap140_ledger_replay_command_surface:array{valid:bool,violations:array<int,string>},
     *   ap141_ledger_projection_registry_contract:array{valid:bool,violations:array<int,string>},
     *   ap142_ledger_projection_inbox_action:array{valid:bool,violations:array<int,string>},
     *   ap143_ledger_projection_curator_action_emission:array{valid:bool,violations:array<int,string>},
     *   ap145_documentation_health_curator_review:array{valid:bool,violations:array<int,string>},
     *   ap146_provider_cost_rate_inbox_replay:array{valid:bool,violations:array<int,string>},
     *   ap148_agent_behavior_identity_fragment:array{valid:bool,violations:array<int,string>},
     *   ap149_agent_behavior_execution_plan:array{valid:bool,violations:array<int,string>},
     *   ap150_agent_behavior_quality_gate:array{valid:bool,violations:array<int,string>},
     *   ap151_agent_behavior_review_action_surface:array{valid:bool,violations:array<int,string>},
     *   ap152_programming_plan_agent_behavior_contract:array{valid:bool,violations:array<int,string>},
     *   ap153_programming_harness_agent_behavior_contract:array{valid:bool,violations:array<int,string>},
     *   ap154_agent_behavior_evidence_ledger:array{valid:bool,violations:array<int,string>},
     *   ap155_agent_behavior_replay_read_model:array{valid:bool,violations:array<int,string>},
     *   ap156_agent_behavior_mcp_report:array{valid:bool,violations:array<int,string>},
     *   ap157_agent_behavior_self_improvement_review:array{valid:bool,violations:array<int,string>},
     *   ap158_agent_behavior_direct_surfaces:array{valid:bool,violations:array<int,string>},
     *   ap159_agent_behavior_dedicated_curator_flow:array{valid:bool,violations:array<int,string>},
     *   ap160_agent_behavior_curator_filter_surface:array{valid:bool,violations:array<int,string>},
     *   ap161_agent_behavior_recurring_schedule:array{valid:bool,violations:array<int,string>},
     *   ap162_agent_behavior_proposal_governance:array{valid:bool,violations:array<int,string>},
     *   ap12_provider_driver_identity_bypass:array{valid:bool,violations:array<int,string>},
     *   ap14_tool_tier_hot_path:array{valid:bool,violations:array<int,string>},
     *   ap15_provider_memory_privacy:array{valid:bool,violations:array<int,string>},
     *   ap16_slo_observability:array{valid:bool,violations:array<int,string>},
     *   ap17_kernel_pipeline_contract:array{valid:bool,violations:array<int,string>},
     *   ap18_repair_loop_contract:array{valid:bool,violations:array<int,string>},
     *   ap19_mcp_domain_catalog_parity:array{valid:bool,violations:array<int,string>},
     *   ap20_cli_fix_dev_repair_alias:array{valid:bool,violations:array<int,string>},
     *   ap21_cli_continue_dev_resume_alias:array{valid:bool,violations:array<int,string>},
     *   ap22_cli_forge_programming_harness_contract:array{valid:bool,violations:array<int,string>},
     *   ap23_chat_dev_programming_contract:array{valid:bool,violations:array<int,string>},
     *   ap24_surface_alias_canonicalization:array{valid:bool,violations:array<int,string>},
     *   ap25_decide_model_selection_contract:array{valid:bool,violations:array<int,string>},
     *   ap26_cli_dev_model_selection_contract:array{valid:bool,violations:array<int,string>},
     *   ap27_chat_model_selection_contract:array{valid:bool,violations:array<int,string>},
     *   ap28_kernel_model_selection_contract_factory:array{valid:bool,violations:array<int,string>},
     *   ap29_programming_surface_contract_factory:array{valid:bool,violations:array<int,string>},
     *   ap30_chat_programming_contract_factory:array{valid:bool,violations:array<int,string>},
     *   ap31_continue_resume_contract_factory:array{valid:bool,violations:array<int,string>},
     *   ap32_fix_contract_factory:array{valid:bool,violations:array<int,string>},
     *   ap36_kernel_pipeline_health_read_model:array{valid:bool,violations:array<int,string>},
     *   ap37_architecture_validation_surface:array{valid:bool,violations:array<int,string>},
     *   ap38_architecture_validation_observability:array{valid:bool,violations:array<int,string>},
     *   ap39_architecture_validation_contract_parity:array{valid:bool,violations:array<int,string>},
     *   ap40_architecture_validation_mcp_tool:array{valid:bool,violations:array<int,string>},
     *   ap41_self_improvement_architecture_validation_review:array{valid:bool,violations:array<int,string>},
     *   ap42_self_improvement_architecture_audit_schedule:array{valid:bool,violations:array<int,string>},
     *   ap43_self_improvement_flow_cadence_contract:array{valid:bool,violations:array<int,string>},
     *   ap44_self_improvement_command_next_run_contract:array{valid:bool,violations:array<int,string>},
     *   ap45_self_improvement_schedule_mcp_tool:array{valid:bool,violations:array<int,string>},
     *   ap46_self_improvement_schedule_health_review:array{valid:bool,violations:array<int,string>},
     *   ap47_self_improvement_schedule_health_ledger_event:array{valid:bool,violations:array<int,string>},
     *   ap48_self_improvement_schedule_replay_read_model:array{valid:bool,violations:array<int,string>},
     *   ap49_self_improvement_schedule_replay_surfaces:array{valid:bool,violations:array<int,string>},
     *   ap50_self_improvement_schedule_replay_mcp_tool:array{valid:bool,violations:array<int,string>},
     *   ap51_self_improvement_schedule_replay_review:array{valid:bool,violations:array<int,string>},
     *   ap52_self_improvement_schedule_replay_review_signal:array{valid:bool,violations:array<int,string>},
     *   ap53_self_improvement_schedule_replay_review_signal_surfaces:array{valid:bool,violations:array<int,string>},
     *   ap54_kernel_pipeline_review_signal:array{valid:bool,violations:array<int,string>},
     *   ap55_repair_loop_review_signal:array{valid:bool,violations:array<int,string>},
     *   ap56_slo_review_signal:array{valid:bool,violations:array<int,string>},
     *   ap57_slo_mcp_tool:array{valid:bool,violations:array<int,string>},
     *   ap58_kernel_pipeline_mcp_tool:array{valid:bool,violations:array<int,string>},
     *   ap59_repair_loop_mcp_tool:array{valid:bool,violations:array<int,string>},
     *   ap60_repair_loop_unavailable_review_signal:array{valid:bool,violations:array<int,string>},
     *   ap61_slo_unavailable_review_signal:array{valid:bool,violations:array<int,string>},
     *   ap62_mcp_replay_unavailable_review_signal:array{valid:bool,violations:array<int,string>},
     *   ap63_schedule_replay_unavailable_shape_parity:array{valid:bool,violations:array<int,string>},
     *   ap64_mcp_replay_window_contract:array{valid:bool,violations:array<int,string>},
     *   ap65_mcp_replay_filter_contract:array{valid:bool,violations:array<int,string>},
     *   ap66_replay_report_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap67_observability_replay_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap68_replay_report_validation_limit_contract:array{valid:bool,violations:array<int,string>},
     *   ap69_self_improvement_runtime_window_contract:array{valid:bool,violations:array<int,string>},
     *   ap70_self_improvement_schedule_window_contract:array{valid:bool,violations:array<int,string>},
     *   ap71_self_improvement_orchestrator_window_contract:array{valid:bool,violations:array<int,string>},
     *   ap72_telemetry_window_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap73_runtime_budget_window_contract:array{valid:bool,violations:array<int,string>},
     *   ap74_ledger_envelope_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap75_ledger_envelope_report_contract:array{valid:bool,violations:array<int,string>},
     *   ap76_telemetry_list_limit_contract:array{valid:bool,violations:array<int,string>},
     *   ap77_programming_iteration_policy_contract:array{valid:bool,violations:array<int,string>},
     *   ap78_open_brain_mcp_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap79_memory_query_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap80_provider_projection_audit_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap81_conversation_context_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap82_retrieval_rank_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap83_atlas_vault_command_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap84_memory_recall_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap85_context_pack_memory_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap86_semantic_context_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap87_provider_projection_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap88_test_command_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap89_engineering_harness_runner_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap90_engineering_harnessability_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap91_engineering_docker_harness_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap92_engineering_test_matrix_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap93_engineering_claude_code_baseline_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap94_engineering_benchmark_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap95_engineering_context_intelligence_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap96_cli_limit_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap97_scheduler_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap98_self_improvement_input_contract:array{valid:bool,violations:array<int,string>},
     *   ap99_provider_usage_performance_contract:array{valid:bool,violations:array<int,string>},
     *   ap100_context_pack_manifest_reflection_contract:array{valid:bool,violations:array<int,string>},
     *   ap101_context_retrieval_router_contract:array{valid:bool,violations:array<int,string>},
     *   ap102_open_brain_retrieval_plan_summary_contract:array{valid:bool,violations:array<int,string>},
     *   ap103_retrieval_required_source_availability_contract:array{valid:bool,violations:array<int,string>},
     *   ap104_retrieval_review_signal_next_action_contract:array{valid:bool,violations:array<int,string>},
     *   ap105_open_brain_retrieval_self_improvement_contract:array{valid:bool,violations:array<int,string>},
     *   ap106_learning_proposed_review_signal_projection_contract:array{valid:bool,violations:array<int,string>},
     *   ap107_proposal_inbox_review_signal_contract:array{valid:bool,violations:array<int,string>},
     *   ap108_learning_proposed_inbox_link_contract:array{valid:bool,violations:array<int,string>},
     *   ap109_operation_completed_inbox_refs_contract:array{valid:bool,violations:array<int,string>},
     *   ap110_schedule_replay_inbox_refs_contract:array{valid:bool,violations:array<int,string>},
     *   ap111_schedule_replay_inbox_refs_surface_parity:array{valid:bool,violations:array<int,string>},
     *   ap112_schedule_replay_inbox_item_hydration:array{valid:bool,violations:array<int,string>},
     *   ap113_schedule_replay_inbox_item_hydration_surface_parity:array{valid:bool,violations:array<int,string>},
     *   ap114_schedule_replay_inbox_hydration_gap_signal:array{valid:bool,violations:array<int,string>},
     *   ap115_self_improvement_schedule_replay_inbox_gap_finding:array{valid:bool,violations:array<int,string>},
     *   ap116_self_improvement_schedule_replay_inbox_gap_emission:array{valid:bool,violations:array<int,string>},
     *   ap117_proposal_inbox_review_signal_severity:array{valid:bool,violations:array<int,string>},
     *   ap118_proposal_review_action_contract:array{valid:bool,violations:array<int,string>},
     *   ap119_cli_inbox_review_action_result_parity:array{valid:bool,violations:array<int,string>},
     *   ap120_inbox_action_evidence_ledger_contract:array{valid:bool,violations:array<int,string>},
     *   ap121_inbox_action_replay_read_model:array{valid:bool,violations:array<int,string>},
     *   ap122_inbox_action_mcp_report:array{valid:bool,violations:array<int,string>},
     *   ap123_self_improvement_inbox_action_replay_review:array{valid:bool,violations:array<int,string>},
     *   ap124_observability_inbox_action_replay:array{valid:bool,violations:array<int,string>},
     *   ap125_inbox_action_report_surfaces:array{valid:bool,violations:array<int,string>},
     *   ap126_architecture_validate_post_ap98_human_output:array{valid:bool,violations:array<int,string>},
     *   ap127_cli_help_architecture_operations_discovery:array{valid:bool,violations:array<int,string>},
     *   ap128_architecture_operations_shared_catalog:array{valid:bool,violations:array<int,string>},
     *   ap129_architecture_operations_mcp_tool:array{valid:bool,violations:array<int,string>},
     *   ap130_architecture_operations_direct_surfaces:array{valid:bool,violations:array<int,string>},
     *   ap131_self_improvement_architecture_operations_review:array{valid:bool,violations:array<int,string>},
     *   ap132_architecture_operations_metadata_contract:array{valid:bool,violations:array<int,string>},
     *   ap133_architecture_operations_filter_contract:array{valid:bool,violations:array<int,string>},
     *   ap173_session_bootstrap_docs_split_plan_contract:array{valid:bool,violations:array<int,string>},
     *   ap174_session_bootstrap_architecture_operations_contract:array{valid:bool,violations:array<int,string>},
     *   ap175_feature_placement_architecture_operations_contract:array{valid:bool,violations:array<int,string>},
     *   ap176_architecture_readiness_snapshot:array{valid:bool,violations:array<int,string>},
     *   ap177_architecture_readiness_mcp_tool:array{valid:bool,violations:array<int,string>},
     *   ap178_provider_release_anti_wrapper_contract:array{valid:bool,violations:array<int,string>},
     *   ap179_voice_realtime_activation_governance:array{valid:bool,violations:array<int,string>},
     *   ap185_voice_realtime_runtime_certification_contract:array{valid:bool,violations:array<int,string>},
     *   ap686_voice_realtime_python_runtime_boundary_contract:array{valid:bool,violations:array<int,string>},
     *   ap687_voice_realtime_production_promotion_gate:array{valid:bool,violations:array<int,string>},
     *   ap683_local_rag_graph_promotion_review:array{valid:bool,violations:array<int,string>},
     *   ap684_external_graph_harness_contract:array{valid:bool,violations:array<int,string>},
     *   ap685_constelacao_lens1_usage_review_contract:array{valid:bool,violations:array<int,string>},
     *   ap168_productive_failure_governance_contract:array{valid:bool,violations:array<int,string>},
     *   ap169_personal_worked_example_privacy_contract:array{valid:bool,violations:array<int,string>},
     *   ap170_predictive_failure_governance_contract:array{valid:bool,violations:array<int,string>},
     *   ap201_runtime_language_boundary_contract:array{valid:bool,violations:array<int,string>}
     * }
     */
    public function complianceReport(): array
    {
        $violations = [];
        foreach ($this->architectureScanChecks() as $key => $scan) {
            $violations[$key] = $scan();
        }

        $report = [
            'ok' => ! in_array(false, array_map(static fn (array $items): bool => $items === [], $violations), true),
        ];
        foreach ($violations as $key => $items) {
            $report[$key] = $this->architectureScanResult($items);
        }

        return $report;
    }

    /**
     * @return array<string,callable():array<int,string>>
     */
    private function architectureScanChecks(): array
    {
        return [
            'ap1_surface_provider_bypass' => fn (): array => $this->scanPhpFilesForForbiddenTokens(app_path('Services/Ai/Surface'), [
                            'App\\Services\\Ai\\Kernel\\Provider\\ProviderDriver',
                            'App\\Services\\Ai\\Provider\\Drivers\\',
                            'App\\Services\\Ai\\ClaudeCliProvider',
                            'App\\Services\\Ai\\CodexCliProvider',
                            'App\\Services\\Ai\\GeminiCliProvider',
                            'App\\Services\\Ai\\AiGatewayService',
                            'App\\Services\\Ai\\AiWorker',
                            'ProviderDriverRegistry',
                            'ClaudeCliProvider',
                            'CodexCliProvider',
                            'GeminiCliProvider',
                            'provider->execute(',
                            'prepareRequest(',
                        ]),
            'ap2_surface_context_bypass' => fn (): array => $this->scanPhpFilesForForbiddenTokens(app_path('Services/Ai/Surface'), [
                            'App\\Services\\Ai\\AiContextPackBuilder',
                            'App\\Services\\Ai\\AtlasOpenBrainContextInjectionService',
                            'App\\Services\\Ai\\AtlasMemoryRegistryService',
                            'App\\Services\\Ai\\EngineeringContextPackService',
                            'App\\Services\\Engineering\\EngineeringContextPackService',
                            'ContextPackBuilder',
                            'OpenBrainContextInjection',
                            'AtlasMemoryRegistry',
                            'EngineeringContextPack',
                            'new ContextPack',
                            'context_pack',
                            'contextPack',
                            'context_refs',
                            'contextRefs',
                            'memory_refs',
                            'memoryRefs',
                        ]),
            'ap6_decision_receipt_propagation' => fn (): array => $this->scanGatewayDecisionReceiptPropagation(),
            'ap13_decision_receipt_runtime_guard' => fn (): array => $this->scanWorkerDecisionReceiptRuntimeGuard(),
            'ap145_documentation_health_curator_review' => fn (): array => $this->scanDocumentationHealthCuratorReview(),
            'ap12_provider_driver_identity_bypass' => fn (): array => $this->scanPhpFilesForForbiddenTokens(
                            app_path('Services/Ai/Provider/Drivers'),
                            [
                                'new ClaudeCliProvider',
                                'new CodexCliProvider',
                                'new GeminiCliProvider',
                                'app(ClaudeCliProvider',
                                'app(CodexCliProvider',
                                'app(GeminiCliProvider',
                                'provider_real_execution_allowed\' => true',
                                'provider_real_execution_allowed" => true',
                            ],
                            [
                                app_path('Services/Ai/Provider/Drivers/ProviderDriverRegistry.php'),
                            ],
                        ),
            'ap14_tool_tier_hot_path' => fn (): array => $this->scanToolTierHotPathPolicy(),
            'ap23_chat_dev_programming_contract' => fn (): array => $this->scanChatDevProgrammingContract(),
            'ap24_surface_alias_canonicalization' => fn (): array => $this->scanSurfaceAliasCanonicalization(),
            'ap25_decide_model_selection_contract' => fn (): array => $this->scanDecideModelSelectionContract(),
            'ap27_chat_model_selection_contract' => fn (): array => $this->scanChatModelSelectionContract(),
            'ap30_chat_programming_contract_factory' => fn (): array => $this->scanChatProgrammingContractFactory(),
            'ap31_continue_resume_contract_factory' => fn (): array => $this->scanContinueResumeContractFactory(),
            'ap32_fix_contract_factory' => fn (): array => $this->scanFixContractFactory(),
            'ap66_replay_report_input_contract' => fn (): array => $this->scanReplayReportInputContract(),
            'ap67_observability_replay_input_contract' => fn (): array => $this->scanObservabilityReplayInputContract(),
            'ap68_replay_report_validation_limit_contract' => fn (): array => $this->scanReplayReportValidationLimitContract(),
            'ap72_telemetry_window_input_contract' => fn (): array => $this->scanTelemetryWindowInputContract(),
            'ap73_runtime_budget_window_contract' => fn (): array => $this->scanRuntimeBudgetWindowContract(),
            'ap76_telemetry_list_limit_contract' => fn (): array => $this->scanTelemetryListLimitContract(),
            'ap78_open_brain_mcp_input_contract' => fn (): array => $this->scanOpenBrainMcpInputContract(),
            'ap79_memory_query_input_contract' => fn (): array => $this->scanMemoryQueryInputContract(),
            'ap81_conversation_context_input_contract' => fn (): array => $this->scanConversationContextInputContract(),
            'ap82_retrieval_rank_input_contract' => fn (): array => $this->scanRetrievalRankInputContract(),
            'ap83_atlas_vault_command_input_contract' => fn (): array => $this->scanAtlasVaultCommandInputContract(),
            'ap84_memory_recall_input_contract' => fn (): array => $this->scanMemoryRecallInputContract(),
            'ap85_context_pack_memory_input_contract' => fn (): array => $this->scanContextPackMemoryInputContract(),
            'ap86_semantic_context_input_contract' => fn (): array => $this->scanSemanticContextInputContract(),
            'ap88_test_command_input_contract' => fn (): array => $this->scanTestCommandInputContract(),
            'ap97_scheduler_input_contract' => fn (): array => $this->scanSchedulerInputContract(),
            'ap100_context_pack_manifest_reflection_contract' => fn (): array => $this->scanContextPackManifestReflectionContract(),
            'ap101_context_retrieval_router_contract' => fn (): array => $this->scanContextRetrievalRouterContract(),
            'ap102_open_brain_retrieval_plan_summary_contract' => fn (): array => $this->scanOpenBrainRetrievalPlanSummaryContract(),
            'ap103_retrieval_required_source_availability_contract' => fn (): array => $this->scanRetrievalRequiredSourceAvailabilityContract(),
            'ap104_retrieval_review_signal_next_action_contract' => fn (): array => $this->scanRetrievalReviewSignalNextActionContract(),
            'ap105_open_brain_retrieval_self_improvement_contract' => fn (): array => $this->scanOpenBrainRetrievalSelfImprovementContract(),
            'ap106_learning_proposed_review_signal_projection_contract' => fn (): array => $this->scanLearningProposedReviewSignalProjectionContract(),
            'ap107_proposal_inbox_review_signal_contract' => fn (): array => $this->scanProposalInboxReviewSignalContract(),
            'ap108_learning_proposed_inbox_link_contract' => fn (): array => $this->scanLearningProposedInboxLinkContract(),
            'ap109_operation_completed_inbox_refs_contract' => fn (): array => $this->scanOperationCompletedInboxRefsContract(),
            'ap117_proposal_inbox_review_signal_severity' => fn (): array => $this->scanProposalInboxReviewSignalSeverity(),
            'ap118_proposal_review_action_contract' => fn (): array => $this->scanProposalReviewActionContract(),
            'ap124_observability_inbox_action_replay' => fn (): array => $this->scanObservabilityInboxActionReplay(),
            'ap173_session_bootstrap_docs_split_plan_contract' => fn (): array => $this->scanSessionBootstrapDocsSplitPlanContract(),
            'ap174_session_bootstrap_architecture_operations_contract' => fn (): array => $this->scanSessionBootstrapArchitectureOperationsContract(),
            'ap175_feature_placement_architecture_operations_contract' => fn (): array => $this->scanFeaturePlacementArchitectureOperationsContract(),
            'ap683_local_rag_graph_promotion_review' => fn (): array => $this->scanLocalRagGraphPromotionReview(),
            'ap684_external_graph_harness_contract' => fn (): array => $this->scanExternalGraphHarnessContract(),
            'ap685_constelacao_lens1_usage_review_contract' => fn (): array => $this->scanConstelacaoLens1UsageReviewContract(),
            'ap168_productive_failure_governance_contract' => fn (): array => $this->scanProductiveFailureGovernanceContract(),
            'ap169_personal_worked_example_privacy_contract' => fn (): array => $this->scanPersonalWorkedExamplePrivacyContract(),
            'ap170_predictive_failure_governance_contract' => fn (): array => $this->scanPredictiveFailureGovernanceContract(),
            'ap201_runtime_language_boundary_contract' => fn (): array => $this->scanRuntimeLanguageBoundaryContract(),
        ] + $this->selfImprovementAudit->checks() + $this->agentBehaviorAudit->checks() + $this->architectureOperationsAudit->checks() + $this->decisionReceiptAudit->checks() + $this->inboxActionAudit->checks() + $this->scheduleReplayAudit->checks() + $this->repairLoopAudit->checks() + $this->engineeringAudit->checks() + $this->cliAudit->checks() + $this->providerAudit->checks() + $this->ledgerAudit->checks() + $this->architectureAudit->checks() + $this->kernelAudit->checks() + $this->voiceAudit->checks() + $this->sloAudit->checks() + $this->mcpAudit->checks() + $this->programmingAudit->checks();
    }

    /**
     * @param  array<int,string>  $violations
     * @return array{valid:bool,violations:array<int,string>}
     */
    private function architectureScanResult(array $violations): array
    {
        return [
            'valid' => $violations === [],
            'violations' => $violations,
        ];
    }
    /**
     * @return array<int,string>
     */
    private function scanSessionBootstrapDocsSplitPlanContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php');
        $commandPath = app_path('Console/Commands/AtlasAiSessionBootstrapCommand.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiGovernanceApiTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $sessionDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md');
        $apDocPath = base_path('docs/ap/AP-173-session-bootstrap-docs-split-plan-contract.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->kernelDocumentationCorpus();
        $sessionDoc = File::exists($sessionDocPath) ? File::get($sessionDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasDocumentationSplitPlanService $splitPlan',
            '$splitOwner = $this->splitOwner',
            "\$splitPlan = \$this->splitPlan->plan(['owner' => \$splitOwner])",
            "'docs_split_plan' => [",
            "'owner' => \$splitOwner",
            "'execution_order' => \$splitPlan['execution_order'] ?? []",
            "'first_doc' => data_get(\$splitPlan, 'docs.0')",
            "'command' => 'php artisan atlas:ai:docs-split-plan --owner='.\$splitOwner.' --json'",
            'private function splitOwner(array $placement, string $task): string',
            "return 'memory_open_brain'",
            "return 'human_knowledge_surface'",
            "return 'tool_runtime'",
            "return 'domain_architecture'",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php: AP-173 session bootstrap must include focused docs_split_plan [{$token}]";
            }
        }

        foreach ([
            'Docs split owner',
            "data_get(\$payload, 'docs_split_plan.owner')",
            "data_get(\$payload, 'docs_split_plan.split_required_count')",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSessionBootstrapCommand.php: AP-173 CLI must surface focused docs split owner [{$token}]";
            }
        }

        $operationsCatalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $operationsCatalog = File::exists($operationsCatalogPath) ? File::get($operationsCatalogPath) : '';
        foreach ([
            "'id' => 'session_bootstrap'",
            "'output_contract' => [",
            "'docs_split_plan' => [",
            "'split_required_count'",
            "'total_split_required_count'",
            "'first_doc'",
        ] as $token) {
            if (! str_contains($operationsCatalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-173 session_bootstrap operation must declare docs_split_plan output contract [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('docs_split_plan.owner', 'knowledge_governance')",
            "assertJsonPath('docs_split_plan.command', 'php artisan atlas:ai:docs-split-plan --owner=knowledge_governance --json')",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiGovernanceApiTest.php: AP-173 API bootstrap docs_split_plan must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'docs_split_plan.owner')",
            "data_get(\$payload, 'docs_split_plan.command')",
            'test_session_bootstrap_focuses_docs_split_plan_for_memory_tasks',
            "'php artisan atlas:ai:docs-split-plan --owner=memory_open_brain --json'",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php: AP-173 CLI bootstrap docs_split_plan must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$bootstrap, 'docs_split_plan.owner')",
            "data_get(\$bootstrap, 'docs_split_plan.command')",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-173 MCP bootstrap docs_split_plan must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-173',
            'Session Bootstrap Docs Split Plan Contract',
            'docs_split_plan',
            'ap173_session_bootstrap_docs_split_plan_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-173 session bootstrap docs split plan contract must be documented [{$token}]";
            }
            if (! str_contains($sessionDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md: AP-173 bootstrap doc must mention docs split plan contract [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-173-session-bootstrap-docs-split-plan-contract.md: AP-173 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSessionBootstrapArchitectureOperationsContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiGovernanceApiTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $sessionDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md');
        $apDocPath = base_path('docs/ap/AP-174-session-bootstrap-architecture-operations-contract.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->kernelDocumentationCorpus();
        $sessionDoc = File::exists($sessionDocPath) ? File::get($sessionDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $operations',
            "'architecture_operations' => \$this->sessionOperations(\$placement['placement'] ?? [])",
            'private function sessionOperations(array $placement = []): array',
            "'architecture_readiness'",
            "'coverage_boundary' => \$coverageBoundary",
            "'safe_next_blocks' => \$safeNextBlocks",
            "'session_bootstrap'",
            "'feature_placement'",
            "'documentation_split_plan'",
            "'architecture_validate'",
            "'provider_projection_status'",
            "'code_intelligence_index'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php: AP-174 session bootstrap must include focused architecture_operations [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')",
            'architecture_operations.operation_ids',
            "'architecture_readiness'",
            "assertJsonPath('coverage_boundary.schema_version', 'atlas.implemented_vs_scaffold.coverage_boundary.v1')",
            "assertJsonPath('safe_next_blocks.0.block', 'Voice Realtime product loop')",
            "'architecture_validate'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiGovernanceApiTest.php: AP-174 API bootstrap architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'architecture_operations.schema_version')",
            "data_get(\$payload, 'architecture_operations.operation_ids')",
            "'architecture_readiness'",
            "data_get(\$payload, 'coverage_boundary.schema_version')",
            "data_get(\$payload, 'safe_next_blocks.0.block')",
            "'provider_projection_status'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php: AP-174 CLI bootstrap architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$bootstrap, 'architecture_operations.operation_ids')",
            "'architecture_readiness'",
            "data_get(\$bootstrap, 'coverage_boundary.schema_version')",
            "data_get(\$bootstrap, 'safe_next_blocks.0.block')",
            "'documentation_split_plan'",
            "'architecture_validate'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-174 MCP bootstrap architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-174',
            'Session Bootstrap Architecture Operations Contract',
            'architecture_operations',
            'voice_realtime_dependencies',
            'ap174_session_bootstrap_architecture_operations_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-174 session bootstrap architecture operations contract must be documented [{$token}]";
            }
            if (! str_contains($sessionDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md: AP-174 bootstrap doc must mention architecture operations contract [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-174-session-bootstrap-architecture-operations-contract.md: AP-174 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanFeaturePlacementArchitectureOperationsContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiGovernanceApiTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-175-feature-placement-architecture-operations-contract.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $operations',
            "'architecture_operations' => \$this->placementOperations(\$placement)",
            'private function placementOperations(array $placement = []): array',
            "'architecture_readiness'",
            "'feature_placement'",
            "'session_bootstrap'",
            "'documentation_split_plan'",
            "'architecture_validate'",
            "'code_intelligence_index'",
            "'voice_realtime_dependencies'",
            "'owner_layer_operations' => [",
            "'runtime' => \$this->operations->summary(['owner_layer' => 'runtime'])",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php: AP-175 feature placement must include focused architecture_operations [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')",
            'architecture_operations.operation_ids',
            'architecture_operations.owner_layer_operations.runtime.operation_ids',
            "'architecture_readiness'",
            "'feature_placement'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiGovernanceApiTest.php: AP-175 API feature placement architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'architecture_operations.schema_version')",
            "data_get(\$payload, 'architecture_operations.operation_ids')",
            "data_get(\$payload, 'architecture_operations.owner_layer_operations.runtime.operation_ids')",
            "'architecture_readiness'",
            "'feature_placement'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php: AP-175 CLI feature placement architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$placement, 'architecture_operations.operation_ids')",
            "data_get(\$placement, 'architecture_operations.owner_layer_operations.runtime.operation_ids')",
            "'architecture_readiness'",
            "'feature_placement'",
            "'architecture_validate'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-175 MCP feature placement architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-175',
            'Feature Placement Architecture Operations Contract',
            'architecture_operations',
            'voice_realtime_dependencies',
            'ap175_feature_placement_architecture_operations_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-175 feature placement architecture operations contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-175-feature-placement-architecture-operations-contract.md: AP-175 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLocalRagGraphPromotionReview(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Context/LocalRagBenchmarkService.php');
        $commandPath = app_path('Console/Commands/AtlasAiLocalRagBenchmarkCommand.php');
        $benchmarkTestPath = base_path('tests/Feature/Ai/AtlasAiLocalRagBenchmarkCommandTest.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $selfImprovementTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $apDocPath = base_path('docs/ap/AP-683-local-rag-graph-promotion-review.md');
        $matrixPath = base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $benchmarkTest = File::exists($benchmarkTestPath) ? File::get($benchmarkTestPath) : '';
        $selfImprovement = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        $selfImprovementTest = File::exists($selfImprovementTestPath) ? File::get($selfImprovementTestPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $matrix = File::exists($matrixPath) ? File::get($matrixPath) : '';

        foreach ([
            "'promotion_allowed' => false",
            "'graph_rag_promotion_allowed' => false",
            "'python_runtime_promotion_allowed' => false",
            "'supersedes_event_required' => 'LOCAL_RAG_GRAPH_PROMOTION_BLOCKED'",
            "'supersede_authority' => 'human_reviewed_curator_proposal_and_future_ap'",
            'future_graph_rag_python_ap',
            'decision_receipt_for_runtime_promotion',
            'reviewable_policy_patch_with_rollback',
            'rollback_plan_required',
            'policy_patch_review_required',
            'promotionReviewPacket',
            'atlas.local_rag_graph_promotion_review_packet.v1',
            'blocked_until_human_review_and_future_ap',
            'enable_python_graph_rag_runtime',
            "'provider_bypass_allowed' => false",
            "'parallel_memory_allowed' => false",
            "'python_graph_rag_is_candidate_runtime_only' => true",
            'recordLocalRagEvent',
            'raw_context_persisted',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Context/LocalRagBenchmarkService.php: AP-683 Local RAG promotion gate must remain proposal-only and fail-closed [{$token}]";
            }
        }

        foreach ([
            'Run a controlled Local RAG router benchmark before Graph RAG/Python runtime promotion.',
            'evidenceLedgerReport',
            'Ledger evidence',
            'Graph RAG promotion',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLocalRagBenchmarkCommand.php: AP-683 command must keep benchmark evidence explicit [{$token}]";
            }
        }

        foreach ([
            'promotion_gate.promotion_allowed',
            'promotion_gate.graph_rag_promotion_allowed',
            'promotion_gate.python_runtime_promotion_allowed',
            'promotion_gate.supersedes_event_required',
            'LOCAL_RAG_GRAPH_PROMOTION_BLOCKED',
            'assertStringNotContainsString',
            'raw_context_persistence_allowed',
            'evidence_ledger.status',
            'promotion_evidence_satisfied',
            'promotion_review_contract.review_packet.schema_version',
            'rollback_plan_required',
            'policy_patch_review_required',
            'disable_python_graph_rag_runtime_policy',
            'atlas_ledger_events_table_unavailable_or_write_failed',
        ] as $token) {
            if (! str_contains($benchmarkTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiLocalRagBenchmarkCommandTest.php: AP-683 benchmark contract must be tested [{$token}]";
            }
        }

        foreach ([
            'atlas.self_improvement.local_rag_graph_promotion.v1',
            'atlas.self_improvement.local_rag_graph_promotion_evidence_block.v1',
            'proposal_only',
            'blocked_until_evidence_persisted',
            'open_reviewable_graph_rag_promotion_proposal',
            'restore_local_rag_evidence_ledger_before_graph_rag_review',
            'local_rag_promotion_requires_persisted_evidence_ledger',
            'promotion_evidence_satisfied',
            'requires_future_ap',
            'requires_decision_receipt',
            'requires_rollback_plan',
            "'review_packet' => data_get(\$report, 'promotion_review_contract.review_packet')",
            "'auto_apply' => false",
        ] as $token) {
            if (! str_contains($selfImprovement, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-683 Self-Improvement finding must stay proposal-only [{$token}]";
            }
        }

        foreach ([
            'atlas.self_improvement.local_rag_graph_promotion.v1',
            'atlas.self_improvement.local_rag_graph_promotion_evidence_block.v1',
            'open_reviewable_graph_rag_promotion_proposal',
            'restore_local_rag_evidence_ledger_before_graph_rag_review',
            'metadata.policy_patch_candidate.status',
            'metadata.policy_patch_candidate.auto_apply',
            'metadata.policy_patch_candidate.requires_future_ap',
            'metadata.policy_patch_candidate.requires_decision_receipt',
            'metadata.policy_patch_candidate.requires_rollback_plan',
            'metadata.review_signal.review_packet.schema_version',
            'metadata.review_signal.review_packet.required_human_decision',
            'metadata.review_signal.review_packet.forbidden_until_review',
            'metadata.benchmark.evidence_ledger.promotion_evidence_satisfied',
        ] as $token) {
            if (! str_contains($selfImprovementTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-683 Self-Improvement finding must be tested [{$token}]";
            }
        }

        foreach ([
            'atlas.local_rag_graph_promotion_review.v1',
            'proposal_gate_scaffold',
            'benchmark verde nao autoriza Graph RAG',
            '`promotion_gate.promotion_allowed` deve ser sempre `false`',
            '`evidence_ledger.promotion_evidence_satisfied=true`',
            'Graph RAG nao pode criar Memory Core, Ledger ou Context Builder paralelo',
            '`LOCAL_RAG_GRAPH_PROMOTION_BLOCKED` so pode ser superseded',
            'restore_local_rag_evidence_ledger_before_graph_rag_review',
            'human_reviewed_curator_proposal_and_future_ap',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-683-local-rag-graph-promotion-review.md: AP-683 authority doc must preserve promotion review doctrine [{$token}]";
            }
        }

        foreach ([
            'AP-683 Local RAG promotion review',
            '`LOCAL_RAG_GRAPH_PROMOTION_BLOCKED`',
            'proposal_only',
            'cerebro Python paralelo',
        ] as $token) {
            if (! str_contains($matrix, $token)) {
                $violations[] = "docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md: AP-683 matrix row/conflict must expose fail-closed status [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanExternalGraphHarnessContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasExternalGraphHarnessService.php');
        $commandPath = app_path('Console/Commands/AtlasAiExternalGraphHarnessCommand.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiExternalGraphHarnessCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiExternalGraphHarnessApiTest.php');
        $serviceTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasExternalGraphHarnessServiceTest.php');
        $operationsTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $apDocPath = base_path('docs/ap/AP-684-graphify-external-graph-harness.md');
        $ownerDocPath = base_path('docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md');
        $matrixPath = base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $serviceTest = File::exists($serviceTestPath) ? File::get($serviceTestPath) : '';
        $operationsTest = File::exists($operationsTestPath) ? File::get($operationsTestPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $ownerDoc = File::exists($ownerDocPath) ? File::get($ownerDocPath) : '';
        $matrix = File::exists($matrixPath) ? File::get($matrixPath) : '';

        foreach ([
            'atlas.external_graph_harness.contract.v1',
            'implemented_read_only_contract',
            'candidate_validation_no_runtime_no_writes',
            'atlas.external_graph_candidate.v1',
            'provider_calls_enabled',
            'writes_memory_registry',
            'writes_context_builder',
            'writes_constelacao',
            'changes_decide_routing',
            'installs_graphify_hooks',
            'review_only_constraints',
            'atlas.external_graph_review_packet.v1',
            'required_human_decision',
            'rollback_plan_required',
            'policy_patch_review_required',
            'forbidden_until_review',
            'surface_direct_external_graph_call',
            'auto_promotion_allowed',
            'blocked_runtime_targets',
            'requires_ap_683_or_successor_for_graph_rag_promotion',
            'promotion_allowed',
            'validateForbiddenCandidateKeys',
            'forbiddenCandidateKeys',
            'memory_write',
            'context_builder_payload',
            'provider_prompt',
            'node_{$index}_duplicate_id',
            'graph_json_to_memory',
            'graph_json_to_context_builder',
            'graph_json_to_constelacao',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasExternalGraphHarnessService.php: AP-684 external graph harness must stay read-only/fail-closed [{$token}]";
            }
        }

        foreach ([
            'atlas:ai:external-graph-harness',
            '--candidate-file',
            'validate graph candidates without writes',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiExternalGraphHarnessCommand.php: AP-684 command contract must expose read-only candidate validation [{$token}]";
            }
        }

        foreach ([
            'test_command_outputs_read_only_contract',
            'test_command_validates_candidate_file_without_writes',
            'accepted_read_only_candidate',
            'writes_constelacao',
            'review_packet',
            'required_human_decision',
            'rollback_plan_required',
            'forbidden_until_review',
            'auto_promotion_allowed',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiExternalGraphHarnessCommandTest.php: AP-684 command behavior must be tested [{$token}]";
            }
        }

        foreach ([
            'test_api_exposes_read_only_external_graph_contract',
            'test_api_validates_candidate_preview_without_operational_writes',
            'writes_memory_registry',
            'writes_context_builder',
            'writes_constelacao',
            'changes_decide_routing',
            'atlas.external_graph_review_packet.v1',
            'required_human_decision',
            'rollback_plan_required',
            'policy_patch_review_required',
            'auto_promotion_allowed',
            'test_api_rejects_malformed_candidate_payload_fail_closed',
            'candidate_must_be_json_object',
            'submit_external_graph_candidate_as_json_object',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiExternalGraphHarnessApiTest.php: AP-684 API behavior must be tested [{$token}]";
            }
        }

        foreach ([
            'test_contract_is_fail_closed_and_read_only',
            'test_accepts_valid_candidate_as_read_only',
            'test_rejects_private_or_unreferenced_candidate',
            'test_rejects_non_graphify_or_pre_promoted_candidates',
            'test_rejects_nested_authority_fields_and_duplicate_nodes',
            'promotion_target_not_allowed_before_review',
            'forbidden_candidate_key:nodes.2.metadata.provider_prompt',
            'forbidden_candidate_key:edges.0.metadata.context_builder_payload',
            'node_2_duplicate_id',
            'provider_prompt_injection',
            'atlas.external_graph_review_packet.v1',
            'required_human_decision',
            'rollback_plan_required',
            'policy_patch_review_required',
            'surface_direct_external_graph_call',
            'python_graph_rag_runtime',
        ] as $token) {
            if (! str_contains($serviceTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasExternalGraphHarnessServiceTest.php: AP-684 service gates must be tested [{$token}]";
            }
        }

        foreach ([
            'external_graph_harness_report',
            'code_intelligence_report',
            '/ai/external-graph-harness',
        ] as $token) {
            if (! str_contains($operationsTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-684 Architecture Operations discovery must be tested [{$token}]";
            }
        }

        foreach ([
            'status: implemented_partial',
            'P0 e P2 read-only estao implementados',
            'nao escrever Memory Registry',
            'nao injetar Context Builder',
            'nao criar estrelas de Constelacao',
            'promotion_allowed=false',
            'review_packet',
            'auto_promotion_allowed',
            'qualquer promocao para Graph RAG exige AP-683',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-684-graphify-external-graph-harness.md: AP-684 doc must preserve implemented_partial/read-only authority [{$token}]";
            }
        }

        foreach ([
            'external_graph_candidate.v1',
            'Architecture Operations review',
            'O grafo externo nunca pula para Memory, Context Builder, Constelacao ou Decide.',
            'promotion_target',
            'atlas.external_graph_review_packet.v1',
        ] as $token) {
            if (! str_contains($ownerDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md: AP-684 owner doc must preserve pipeline and promotion boundaries [{$token}]";
            }
        }

        foreach ([
            'External Graph Harness / Graphify AP-684',
            'implemented_partial',
            'review_only_constraints',
            'nao rodar Graphify direto em memoria/docs privados',
            'nao promover Graphify para memoria/contexto/runtime sem AP futuro',
        ] as $token) {
            if (! str_contains($matrix, $token)) {
                $violations[] = "docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md: AP-684 matrix row must preserve partial/read-only status [{$token}]";
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    /**
     * @return array<int,string>
     */
    private function scanConstelacaoLens1UsageReviewContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Surface/ConstelacaoPositionsService.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasConstelacaoPositionsApiTest.php');
        $selfImprovementTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docPath = base_path('docs/engineering-knowledge-base/atlas-constelacao-surface.md');
        $apDocPath = base_path('docs/ap/AP-685-constelacao-lens1-usage-review.md');
        $matrixPath = base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $selfImprovement = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $selfImprovementTest = File::exists($selfImprovementTestPath) ? File::get($selfImprovementTestPath) : '';
        $doc = File::exists($docPath) ? File::get($docPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $matrix = File::exists($matrixPath) ? File::get($matrixPath) : '';

        foreach ([
            "'allowed_lenses' => ['bilderatlas']",
            'command_sky_requires_future_ap_human_review_and_decision_receipt',
            'unsupported_lens_requires_future_ap_human_review_and_decision_receipt',
            'lensGateReason',
            "'lens_role' => 'contemplative_serendipity'",
            "'operational_chrome_allowed' => false",
            "'raw_reading_allowed' => false",
            "'raw_content_allowed' => false",
            "'graph_rag_status' => 'future_governed'",
            "'provider_bypass_allowed' => false",
            "'parallel_memory_allowed' => false",
            "'graph_rag_promotion_allowed' => false",
            "'python_runtime_allowed' => false",
            "'lens2_promotion_allowed' => false",
            "'command_sky_allowed' => false",
            "'lineage_allowed' => false",
            "'graph_rag_positioning_allowed' => false",
            "'observation_window_days_required' => 30",
            "'promotion_allowed' => false",
            'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
            "'requires_curator_usage_review' => true",
            'atlas.constelacao.lens1_usage_review.v1',
            'approve_or_reject_constelacao_lens1_promotion_after_usage_review',
            "'rollback_plan_required' => true",
            "'policy_patch_review_required' => true",
            "'forbidden_until_review' => [",
            "'enable_command_sky'",
            "'keep_graph_rag_positioning_disabled'",
            "'auto_promotion_allowed' => false",
            "'blocked_targets' => [",
            "'graph_rag_positioning'",
            "'python_graph_rag_runtime'",
            'only_future_ap_with_human_review_curator_proposal_decision_receipt_and_rollback_plan',
            'lens1_must_prove_contemplative_value_before_operational_or_graph_promotion',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Surface/ConstelacaoPositionsService.php: AP-685 Constelacao Lente 1 must remain contemplative/fail-closed [{$token}]";
            }
        }

        foreach ([
            'atlas.self_improvement.constelacao_usage_review.v1',
            'Revisar uso real da Constelacao Lente 1',
            'review_constelacao_lens1_usage_after_observation_window',
            'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
            'Graph RAG/Python segue bloqueado',
            'CONSTELACAO_POSITIONS_SERVED',
            'constelacao_lens1_usage_requires_human_review_before_lens2',
            'atlas.constelacao.lens1_usage_review.v1',
            'approve_or_reject_constelacao_lens1_promotion_after_usage_review',
            "'rollback_plan_required' => true",
            "'policy_patch_review_required' => true",
            "'forbidden_until_review' => [",
            "'enable_command_sky'",
            "'keep_graph_rag_positioning_disabled'",
            "'promotion_allowed' => false",
            "'auto_promotion_allowed' => false",
            "'python_graph_rag_runtime'",
            'only_future_ap_with_human_review_curator_proposal_decision_receipt_and_rollback_plan',
            "'graph_rag_promotion_allowed' => false",
            "'python_runtime_allowed' => false",
        ] as $token) {
            if (! str_contains($selfImprovement, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-685 Curator finding must review usage without promoting runtime [{$token}]";
            }
        }

        foreach ([
            'test_positions_endpoint_returns_governed_constelacao_payload_without_raw_content',
            "assertJsonPath('lens_maturity_gate.lens2_promotion_allowed', false)",
            "assertJsonPath('lens_maturity_gate.command_sky_allowed', false)",
            "assertJsonPath('lens_maturity_gate.graph_rag_positioning_allowed', false)",
            "assertJsonPath('lens_maturity_gate.promotion_allowed', false)",
            "assertJsonPath('lens1_usage_review_contract.schema_version', 'atlas.constelacao.lens1_usage_review.v1')",
            "assertJsonPath('lens1_usage_review_contract.required_human_decision', 'approve_or_reject_constelacao_lens1_promotion_after_usage_review')",
            "assertJsonPath('lens1_usage_review_contract.rollback_plan_required', true)",
            "assertJsonPath('lens1_usage_review_contract.policy_patch_review_required', true)",
            "assertJsonPath('lens1_usage_review_contract.promotion_allowed', false)",
            "assertJsonPath('lens1_usage_review_contract.auto_promotion_allowed', false)",
            "assertJsonPath('ui_contract.lens_role', 'contemplative_serendipity')",
            "assertJsonPath('ui_contract.operational_chrome_allowed', false)",
            "assertJsonPath('position_engine.graph_rag_status', 'future_governed')",
            "assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.graph_rag_promotion_allowed', false)",
            "assertJsonPath('position_engine.semantic_positioning_readiness.promotion_gate.python_runtime_allowed', false)",
            'test_unknown_lens_request_is_sanitized_preserved_and_blocked',
            "assertJsonPath('requested_lens', 'graph-rag-admin')",
            "assertJsonPath('lens_gate.reason', 'unsupported_lens_requires_future_ap_human_review_and_decision_receipt')",
            'assertStringNotContainsString',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasConstelacaoPositionsApiTest.php: AP-685 API must prove Lente 1 privacy and promotion gates [{$token}]";
            }
        }

        foreach ([
            'test_docs_drift_review_emits_constelacao_lens_usage_review_without_graph_promotion',
            'atlas.self_improvement.constelacao_usage_review.v1',
            'metadata.usage_review_contract.schema_version',
            'metadata.usage_review_contract.required_human_decision',
            'metadata.usage_review_contract.rollback_plan_required',
            'metadata.usage_review_contract.forbidden_until_review',
            'review_constelacao_lens1_usage_after_observation_window',
            'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
            'metadata.promotion_gate.promotion_allowed',
            'metadata.promotion_gate.graph_rag_promotion_allowed',
            'metadata.promotion_gate.python_runtime_allowed',
        ] as $token) {
            if (! str_contains($selfImprovementTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-685 Curator usage review must be tested [{$token}]";
            }
        }

        foreach ([
            'Constelacao e uma surface contemplativa do Atlas',
            'Lente 1 Bilderatlas e sempre o estado default',
            'Command Sky/linhagem so entra por gesto explicito em fase posterior',
            'Graph RAG/Python permanece bloqueado',
            'fallback silencioso e proibido',
            'Curator usage review',
            '30 dias de uso real',
            'atlas.constelacao.lens1_usage_review.v1',
            'promotion_allowed=false',
            'auto-promotion proibida',
            'review humano e Decision Receipt',
            'implemented_partial',
        ] as $token) {
            if (! str_contains($doc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-constelacao-surface.md: AP-685 doc must preserve Lente 1 usage review doctrine [{$token}]";
            }
        }

        foreach ([
            'AP-685 - Constelacao Lens 1 Usage Review',
            'status: implemented_partial',
            'atlas.constelacao.lens1_usage_review.v1',
            'Lens 1 is always `bilderatlas`',
            '`promotion_allowed=false`',
            '`auto_promotion_allowed=false`',
            '`command_sky_allowed=false`',
            '`graph_rag_positioning_allowed=false`',
            'Silent fallback is not',
            'Any Graph RAG promotion must pass AP-683',
            'CONSTELACAO_POSITIONS_SERVED',
            '30-day observation window',
            'Do not build Command Sky here',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-685-constelacao-lens1-usage-review.md: AP-685 canonical AP doc must exist and preserve Lente 1 usage review contract [{$token}]";
            }
        }

        foreach ([
            'Constelacao Lente 1 usage review',
            'zero elementos operacionais na Lente 1',
            'Coletar uso real por 30 dias',
            'manter Graph RAG/Command Sky bloqueados ate AP/review',
        ] as $token) {
            if (! str_contains($matrix, $token)) {
                $violations[] = "docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md: AP-685 matrix safe-next row must preserve Lente 1 gate [{$token}]";
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    /**
     * @return array<int,string>
     */
    private function scanObservabilityInboxActionReplay(): array
    {
        $violations = [];
        $controllerPath = app_path('Http/Controllers/AiObservabilityController.php');
        $testPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-124-observability-inbox-action-replay.md');

        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            '$inboxActions = $ledgerReplay->inboxActionReportForWindow($since)',
            "'inbox_actions' => \$inboxActions",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: AP-124 observability must expose Inbox action replay [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_includes_inbox_action_replay_summary',
            'LedgerEventType::InboxActionRecorded',
            "assertJsonPath('inbox_actions.available', true)",
            "assertJsonPath('inbox_actions.review_signal.recommended_action', 'open_reviewable_inbox_action_evidence_proposal')",
            'review_patch_action_without_diff_refs',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-124 observability Inbox action replay must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-124',
            'Observability Inbox Action Replay',
            'inbox_actions',
            'inboxActionReportForWindow',
            'open_reviewable_inbox_action_evidence_proposal',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-124 observability Inbox action replay must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-124-observability-inbox-action-replay.md: AP-124 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProposalReviewActionContract(): array
    {
        $violations = [];
        $actionsPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $testPath = base_path('tests/Feature/MobileGatewayTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-118-proposal-review-action-contract.md');

        $actions = File::exists($actionsPath) ? File::get($actionsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            '$proposalContract = $this->array(data_get($payload, \'proposal_contract\'))',
            "'proposal_contract' => \$proposalContract",
            "'review_signal' => \$this->array(data_get(\$proposalContract, 'review_signal'))",
            "'recommended_action' => \$this->string(data_get(\$proposalContract, 'review_signal.recommended_action'))",
            "'diff_refs' => \$this->array(data_get(\$proposalContract, 'diff_refs'))",
        ] as $token) {
            if (! str_contains($actions, $token)) {
                $violations[] = "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-118 review_patch must expose proposal contract fields directly [{$token}]";
            }
        }

        foreach ([
            "'review_patch'",
            'result.payload.action',
            'result.payload.diff_refs.0.path',
            'result.payload.proposal_contract.diff_refs.0.path',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/MobileGatewayTest.php: AP-118 review_patch action contract must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-118',
            'Proposal Review Action Contract',
            'review_patch',
            'proposal_contract',
            'recommended_action',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-118 review action contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-118-proposal-review-action-contract.md: AP-118 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProposalInboxReviewSignalSeverity(): array
    {
        $violations = [];
        $emitterPath = app_path('Services/Ai/Mobile/ProposalInboxEmitter.php');
        $testPath = base_path('tests/Unit/Ai/ProposalInboxEmitterTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-117-proposal-inbox-review-signal-severity-contract.md');

        $emitter = File::exists($emitterPath) ? File::get($emitterPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            '$reviewSignal = $this->array($metadata[\'review_signal\'] ?? [])',
            "'severity' => \$this->severityFromReviewSignal(\$reviewSignal)",
            "'priority_score' => \$this->priorityFromReviewSignal(\$reviewSignal)",
            'private function severityFromReviewSignal',
            'private function priorityFromReviewSignal',
        ] as $token) {
            if (! str_contains($emitter, $token)) {
                $violations[] = "app/Services/Ai/Mobile/ProposalInboxEmitter.php: AP-117 Proposal Inbox must map review_signal severity into Inbox severity/priority [{$token}]";
            }
        }

        foreach ([
            'test_proposal_maps_review_signal_to_inbox_severity_and_priority',
            "'severity' => 'high'",
            "data_get(\$inbox->created, 'severity')",
            "data_get(\$inbox->created, 'priority_score')",
            "'critical'",
            '85',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/ProposalInboxEmitterTest.php: AP-117 severity/priority mapping must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-117',
            'Proposal Inbox Review Signal Severity',
            'review_signal.severity',
            'priority_score',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-117 proposal severity mapping must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-117-proposal-inbox-review-signal-severity-contract.md: AP-117 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanOperationCompletedInboxRefsContract(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'LedgerEventType::OperationCompleted',
            "'emitted_count' => count(\$emitted)",
            "'emitted_inbox_item_ids' => array_values(\$emitted)",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-109 OperationCompleted must preserve emitted inbox ids [{$token}]";
            }
        }

        foreach ([
            'LedgerEventType::OperationCompleted->value',
            'emitted_inbox_item_ids',
            'emitted_count',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-109 OperationCompleted inbox refs must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-109',
            'OperationCompleted Inbox Refs',
            'emitted_inbox_item_ids',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-109 OperationCompleted inbox refs contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLearningProposedInboxLinkContract(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            '$emittedByDedupeKey = [];',
            '$emittedByDedupeKey[$dedupeKey] = $item->id;',
            '$emittedInboxItemId = $emittedByDedupeKey[(string) ($finding[\'dedupe_key\'] ?? \'\')] ?? null;',
            "'emitted_to_inbox' => \$emittedInboxItemId !== null",
            "'emitted_inbox_item_id' => \$emittedInboxItemId",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-108 LearningProposed must link emitted inbox item to finding [{$token}]";
            }
        }

        foreach ([
            'test_learning_proposed_event_links_emitted_inbox_item_to_finding',
            'emitted_to_inbox',
            'emitted_inbox_item_id',
            'ProposalInboxEmitter::class',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-108 LearningProposed inbox link must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-108',
            'LearningProposed Inbox Link',
            'emitted_inbox_item_id',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-108 LearningProposed inbox link contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProposalInboxReviewSignalContract(): array
    {
        $violations = [];
        $emitterPath = app_path('Services/Ai/Mobile/ProposalInboxEmitter.php');
        $testPath = base_path('tests/Unit/Ai/ProposalInboxEmitterTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $emitter = File::exists($emitterPath) ? File::get($emitterPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'private function proposalPayload(array $data, string $problem, string $solution, string $worthIt): array',
            "'proposal_contract' => [",
            "'schema_version' => \$metadata['schema_version'] ?? null",
            "'review_signal' => \$this->array(\$metadata['review_signal'] ?? [])",
            "'source_refs' => \$this->array(\$data['source_refs'] ?? [])",
        ] as $token) {
            if (! str_contains($emitter, $token)) {
                $violations[] = "app/Services/Ai/Mobile/ProposalInboxEmitter.php: AP-107 Proposal Inbox must preserve schema/review_signal/source refs [{$token}]";
            }
        }

        foreach ([
            'test_proposal_preserves_review_signal_contract_in_bundle_and_inbox_payload',
            'payload.proposal_contract.schema_version',
            'payload.proposal_contract.review_signal.status',
            'raw_payload.proposal_contract.review_signal.status',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/ProposalInboxEmitterTest.php: AP-107 Proposal Inbox review_signal contract must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-107',
            'Proposal Inbox Review Signal',
            'proposal_contract.review_signal',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-107 Proposal Inbox review_signal contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLearningProposedReviewSignalProjectionContract(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            '$metadata = (array) ($finding[\'metadata\'] ?? []);',
            "'schema_version' => \$metadata['schema_version'] ?? null",
            "'review_signal' => (array) (\$metadata['review_signal'] ?? [])",
            "'source_types' => collect((array) (\$finding['source_refs'] ?? []))",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-106 LearningProposed projection must preserve review_signal/schema/source types [{$token}]";
            }
        }

        foreach ([
            'finding.schema_version',
            'finding.review_signal.status',
            'finding.review_signal.recommended_action',
            'finding.source_types',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-106 LearningProposed projection must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-106',
            'LearningProposed Review Signal Projection',
            'finding.review_signal',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-106 LearningProposed projection contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanOpenBrainRetrievalSelfImprovementContract(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'use App\Models\AtlasOpenBrainAccessLog;',
            'private function openBrainRetrievalFindings(int $hours, array $filters = []): array',
            "Schema::hasTable('atlas_open_brain_access_logs')",
            "data_get(\$summary, 'retrieval_plan.review_signal.status')",
            "'required_unavailable_source_counts' => \$requiredUnavailableSourceCounts",
            "'self-improvement:open-brain-retrieval:'",
            "'atlas.self_improvement.open_brain_retrieval.v1'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-105 Open Brain retrieval signal must feed Self-Improvement [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_open_brain_retrieval_required_source_gaps',
            "'recommended_action' => 'refresh_evidence_replay_or_attach_trace_before_retry'",
            'required_unavailable_source_counts',
            'self-improvement:open-brain-retrieval:',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-105 Open Brain retrieval Self-Improvement contract must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-105',
            'Open Brain Retrieval Self-Improvement',
            'self-improvement:open-brain-retrieval',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-105 Open Brain retrieval Self-Improvement contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRetrievalReviewSignalNextActionContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/AtlasOpenBrainContextInjectionService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            "'review_signal' => \$reviewSignal",
            'private function retrievalReviewSignal(array $availability): array',
            'private function retrievalRecommendedAction(array $sources): string',
            "'status' => 'blocking'",
            "'recommended_action' => \$this->retrievalRecommendedAction(\$requiredUnavailable)",
            'private function nextActions(array $warnings, array $summary = []): array',
            "data_get(\$summary, 'retrieval_plan.review_signal.recommended_action')",
            'Refresh evidence replay or attach trace/envelope evidence before retrying.',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainContextInjectionService.php: AP-104 retrieval review_signal/next_actions contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'summary.retrieval_plan.review_signal.status',
            'summary.retrieval_plan.review_signal.recommended_action',
            'Refresh evidence replay or attach trace/envelope evidence before retrying.',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php: AP-104 retrieval review_signal/next_actions must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-104',
            'Retrieval Review Signal',
            'refresh_evidence_replay_or_attach_trace_before_retry',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-104 retrieval review_signal contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRetrievalRequiredSourceAvailabilityContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/AtlasOpenBrainContextInjectionService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'private function retrievalSourceAvailability(array $selected, array $contextRefs, array $knowledgeRefs, array $codeRefs, array $contextPack): array',
            "'available_sources' => array_values(array_keys(array_filter",
            "'unavailable_sources' => array_values(array_keys(array_filter",
            "'required_unavailable_sources' => array_values(array_keys(array_filter",
            'private function evidenceReplayCount(array $contextRefs, array $contextPack): int',
            'private function graphRetrievalCount(array $contextRefs, array $contextPack): int',
            'private function retrievalPlanWarnings(array $retrievalPlan): array',
            "'retrieval_required_source_unavailable'",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainContextInjectionService.php: AP-103 required retrieval source availability gate is incomplete [{$token}]";
            }
        }

        foreach ([
            'test_required_open_brain_fails_closed_when_required_retrieval_source_is_unavailable',
            'summary.retrieval_plan.required_unavailable_sources',
            'retrieval_required_source_unavailable',
            'failed_closed',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php: AP-103 retrieval availability gate must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-103',
            'Retrieval Required Source Availability',
            'retrieval_required_source_unavailable',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-103 retrieval availability contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanOpenBrainRetrievalPlanSummaryContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/AtlasOpenBrainContextInjectionService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            "'retrieval_plan' => \$retrievalPlan",
            'private function retrievalPlanSummary(array $retrievalPlan, array $contextRefs, array $knowledgeRefs, array $codeRefs, array $contextPack): ?array',
            "'selected_sources' => array_values(array_map",
            "'required_sources' => array_values(array_map",
            "'max_context_refs' => data_get(\$retrievalPlan, 'budgets.max_context_refs')",
            "'provider_safe_only' => (bool) data_get(\$retrievalPlan, 'policy.provider_safe_only', true)",
            "'- retrieval_plan: mode='",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainContextInjectionService.php: Open Brain must summarize AP-101 retrieval plan for audit and prompt header [{$token}]";
            }
        }

        foreach ([
            'test_retrieval_plan_is_summarized_for_open_brain_audit_and_prompt_header',
            'summary.retrieval_plan.selected_sources',
            'summary.retrieval_plan.required_sources',
            'retrieval_plan: mode=audit_heavy',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php: AP-102 Open Brain retrieval plan summary must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-102',
            'Open Brain Retrieval Plan Summary',
            'summary.retrieval_plan',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-102 Open Brain retrieval summary contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanContextRetrievalRouterContract(): array
    {
        $violations = [];
        $routerPath = app_path('Services/Ai/Context/ContextRetrievalRouter.php');
        $builderPath = app_path('Services/Ai/AiContextPackBuilder.php');
        $packPath = app_path('Services/Ai/ValueObjects/AiContextPack.php');
        $testPath = base_path('tests/Unit/Ai/Context/ContextRetrievalRouterTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $router = File::exists($routerPath) ? File::get($routerPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $pack = File::exists($packPath) ? File::get($packPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'final class ContextRetrievalRouter',
            "public const SCHEMA_VERSION = 'atlas.context.retrieval_plan.v1'",
            "'vector_retrieval'",
            "'graph_retrieval'",
            "'evidence_replay'",
            "'code_intelligence'",
            "'memory_signals'",
            "'do_not_create_parallel_memory' => true",
        ] as $token) {
            if (! str_contains($router, $token)) {
                $violations[] = "app/Services/Ai/Context/ContextRetrievalRouter.php: AP-101 retrieval router contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private ContextRetrievalRouter $retrievalRouter',
            '$retrievalPlan = $this->retrievalRouter->plan($input, $task, $payload, $options)',
            '\'retrieval\' => $retrievalPlan',
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/AiContextPackBuilder.php: Context Builder must attach AP-101 retrieval plan [{$token}]";
            }
        }

        foreach ([
            'Retrieval Router Plan',
            'selected_sources',
        ] as $token) {
            if (! str_contains($pack, $token)) {
                $violations[] = "app/Services/Ai/ValueObjects/AiContextPack.php: prompt context must expose AP-101 retrieval plan [{$token}]";
            }
        }

        foreach ([
            'ContextRetrievalRouterTest',
            'test_builds_provider_safe_retrieval_plan_for_programming_context',
            'test_marks_evidence_required_for_high_risk_and_graph_for_architecture_questions',
            'atlas.context.retrieval_plan.v1',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/ContextRetrievalRouterTest.php: AP-101 retrieval router must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-101',
            'Retrieval Router',
            'atlas.context.retrieval_plan.v1',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-101 retrieval router contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanContextPackManifestReflectionContract(): array
    {
        $violations = [];
        $contextPackPath = app_path('Services/Ai/ValueObjects/AiContextPack.php');
        $gatePath = app_path('Services/Ai/Context/ContextPackSelfReflectionGate.php');
        $testPath = base_path('tests/Unit/Ai/Context/ContextPackSelfReflectionGateTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $contextPack = File::exists($contextPackPath) ? File::get($contextPackPath) : '';
        $gate = File::exists($gatePath) ? File::get($gatePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'private function withManifest(array $data, array $contextRefs): array',
            "'schema_version' => 'atlas.context_pack.manifest.v1'",
            "'created_at' => \$createdAt->toJSON()",
            "'expires_at' => \$createdAt->copy()->addSeconds(\$ttlSeconds)->toJSON()",
            "'sources' => \$sources",
            "'context_ref_hash' => hash('sha256'",
        ] as $token) {
            if (! str_contains($contextPack, $token)) {
                $violations[] = "app/Services/Ai/ValueObjects/AiContextPack.php: Context Pack must carry AP-100 manifest with sources, created_at and expires_at [{$token}]";
            }
        }

        foreach ([
            'class ContextPackSelfReflectionGate',
            "public const STATUS_SUFFICIENT = 'sufficient'",
            "public const STATUS_INSUFFICIENT = 'insufficient'",
            "public const STATUS_CONTRADICTORY = 'contradictory'",
            "public const STATUS_RISKY = 'risky'",
            'public function assess(AiContextPack|array $contextPack): array',
            "'schema_version' => 'atlas.context_pack.self_reflection.v1'",
            'refresh_or_request_context',
            'surface_conflict_before_execution',
            'require_review_before_execution',
        ] as $token) {
            if (! str_contains($gate, $token)) {
                $violations[] = "app/Services/Ai/Context/ContextPackSelfReflectionGate.php: Self-Reflection Gate must classify sufficient/insufficient/contradictory/risky context [{$token}]";
            }
        }

        foreach ([
            'ContextPackSelfReflectionGateTest',
            'test_context_pack_manifest_has_sources_created_at_and_expires_at',
            'test_self_reflection_gate_classifies_sufficient_insufficient_contradictory_and_risky_context',
            'atlas.context_pack.manifest.v1',
            'atlas.context_pack.self_reflection.v1',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/ContextPackSelfReflectionGateTest.php: AP-100 manifest and reflection gate must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-100',
            'Context Pack Manifest',
            'Self-Reflection Gate',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-100 context manifest/reflection contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRuntimeLanguageBoundaryContract(): array
    {
        $violations = [];
        $runtimeDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md');
        $staticScansDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');
        $architectureTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');
        $runtimeReportPath = app_path('Services/Ai/Kernel/Architecture/AtlasRuntimeLanguageBoundaryReportService.php');
        $runtimeCommandTestPath = base_path('tests/Feature/Ai/AtlasAiRuntimeBoundaryCommandTest.php');
        $runtimeApiTestPath = base_path('tests/Feature/Ai/AtlasAiRuntimeBoundaryApiTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $architectureOperationsCatalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $architectureOperationsCommandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php');
        $architectureOperationsApiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php');
        $architectureOperationsCatalogTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $featurePlacementPath = app_path('Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php');
        $featurePlacementTestPath = base_path('tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php');

        $runtimeDoc = File::exists($runtimeDocPath) ? File::get($runtimeDocPath) : '';
        $staticScansDoc = File::exists($staticScansDocPath) ? File::get($staticScansDocPath) : '';
        $architectureTest = File::exists($architectureTestPath) ? File::get($architectureTestPath) : '';
        $runtimeReport = File::exists($runtimeReportPath) ? File::get($runtimeReportPath) : '';
        $runtimeCommandTest = File::exists($runtimeCommandTestPath) ? File::get($runtimeCommandTestPath) : '';
        $runtimeApiTest = File::exists($runtimeApiTestPath) ? File::get($runtimeApiTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $architectureOperationsCatalog = File::exists($architectureOperationsCatalogPath) ? File::get($architectureOperationsCatalogPath) : '';
        $architectureOperationsCommandTest = File::exists($architectureOperationsCommandTestPath) ? File::get($architectureOperationsCommandTestPath) : '';
        $architectureOperationsApiTest = File::exists($architectureOperationsApiTestPath) ? File::get($architectureOperationsApiTestPath) : '';
        $architectureOperationsCatalogTest = File::exists($architectureOperationsCatalogTestPath) ? File::get($architectureOperationsCatalogTestPath) : '';
        $featurePlacement = File::exists($featurePlacementPath) ? File::get($featurePlacementPath) : '';
        $featurePlacementTest = File::exists($featurePlacementTestPath) ? File::get($featurePlacementTestPath) : '';

        foreach ([
            'Laravel decide e governa',
            'python_ai_data',
            'go_edge',
            'swift_native_mac',
            'DecisionReceipt',
            'Adapters Laravel podem manter manifest, chunking, privacy gate, chamada governada de',
            'Eles nao podem virar Vector RAG',
            'Graph RAG, reranker, clustering',
        ] as $token) {
            if (! str_contains($runtimeDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md: runtime language boundary doctrine must document [{$token}]";
            }
        }

        foreach ([
            'Runtime language boundary',
            'Heavy RAG/ML libraries or direct Go/Swift native runtime shortcuts in Laravel `app/`, including services, semantic adapters, controllers, jobs and commands, must fail runtime language boundary',
            'EmbeddingService',
        ] as $token) {
            if (! str_contains($staticScansDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/kernel/static-scans.md: AP-201 runtime language boundary scan must be documented [{$token}]";
            }
        }

        foreach ([
            'ap201_runtime_language_boundary_contract',
            'runtime language boundary',
        ] as $token) {
            if (! str_contains($architectureTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: AP-201 runtime language boundary scan must be asserted [{$token}]";
            }
        }

        foreach ([
            'AtlasRuntimeLanguageBoundaryReportService',
            'atlas.runtime_boundary_preflight_gate.v1',
            'runtime_promotion_policy',
            'run_feature_placement_strict',
            'skip_decision_receipt_for_runtime',
            'atlas.runtime_promotion_policy.v1',
            'runtime_invocation_contract',
            'runtime_owner_map',
            'decision_receipt_hash',
            'evidence_sink',
            'create_parallel_context_store',
            'python_ai_data',
            'go_edge',
            'swift_native_mac',
            'runtimes/python',
            'runtimes/go',
            'runtimes/swift',
            'atlas_runtime_boundary',
        ] as $token) {
            if (! str_contains($runtimeReport, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasRuntimeLanguageBoundaryReportService.php: AP-201 runtime boundary report must expose executable invocation contract [{$token}]";
            }
        }

        foreach ([
            'preflight_gate.schema_version',
            'runtime_promotion_policy.schema_version',
            'skip_decision_receipt_for_runtime',
            'runtime_invocation_contract.schema_version',
            'runtime_owner_map.python_ai_data.allowed_write_scope',
            'decision_receipt_hash',
        ] as $token) {
            if (! str_contains($runtimeCommandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiRuntimeBoundaryCommandTest.php: AP-201 CLI runtime invocation contract must be asserted [{$token}]";
            }
            if (! str_contains($runtimeApiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiRuntimeBoundaryApiTest.php: AP-201 API runtime invocation contract must be asserted [{$token}]";
            }
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-201 MCP runtime invocation contract must be asserted [{$token}]";
            }
        }

        foreach ([
            "'id' => 'runtime_language_boundary'",
            "'owner_layer' => 'runtime'",
            "'governed_runtimes' => ['python_ai_data', 'go_edge', 'swift_native_mac']",
            "'pre_implementation_gate' => true",
            "'required_for_terms' => ['python', 'rag', 'embedding', 'faiss', 'go', 'swift', 'livekit', 'ml']",
        ] as $token) {
            if (! str_contains($architectureOperationsCatalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-201 runtime boundary operation must be discoverable by runtime owner layer [{$token}]";
            }
        }

        foreach ([
            "'--owner-layer' => 'runtime'",
            "['runtime_language_boundary']",
            'architecture_operations.commands.0.owner_layer',
            'architecture_operations.commands.0.pre_implementation_gate',
            'architecture_operations.commands.0.governed_runtimes',
            "'owner_layer' => 'runtime'",
        ] as $token) {
            if (! str_contains($architectureOperationsCommandTest, $token)
                && ! str_contains($architectureOperationsApiTest, $token)
                && ! str_contains($architectureOperationsCatalogTest, $token)
                && ! str_contains($mcpTest, $token)) {
                $violations[] = "tests: AP-201 runtime boundary owner-layer discovery must be asserted across CLI/API/catalog/MCP [{$token}]";
            }
        }

        foreach ([
            'private function runtimeOwnerDocs',
            'private function runtimeInvocationContract',
            'AtlasRuntimeLanguageBoundaryReportService $runtimeBoundary',
            '$this->runtimeBoundary->invocationContract()',
            'selected_runtime_family',
            'placement_runtime_alias',
            'do_not_implement_heavy_rag_embeddings_rerank_graph_or_ml_inside_laravel_app',
            'do_not_put_provider_policy_memory_or_domain_decision_inside_go_edge_runtime',
            'do_not_capture_mic_screen_keychain_touchid_or_accessibility_without_kernel_policy_and_user_consent',
            'run_runtime_language_boundary_before_and_after_changes',
            'declare_kernel_decision_receipt_contract_for_runtime_invocation',
            'prove_runtime_outputs_return_to_evidence_ledger_or_output_renderer',
            'faiss',
            'reranker',
            'go_edge_concurrency',
            'swift_native_mac',
        ] as $token) {
            if (! str_contains($featurePlacement, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php: AP-201 feature placement must route specialized runtimes with owner docs and forbidden scopes [{$token}]";
            }
        }

        foreach ([
            'test_place_feature_routes_graph_rag_to_python_runtime_boundary',
            'test_place_feature_routes_go_edge_streaming_to_go_runtime_boundary',
            'test_place_feature_routes_swift_native_mac_to_swift_runtime_boundary',
            'implementation_contract.runtime_invocation_contract.schema_version',
            'implementation_contract.runtime_invocation_contract.selected_runtime_family',
            'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md',
            'do_not_implement_heavy_rag_embeddings_rerank_graph_or_ml_inside_laravel_app',
            'do_not_put_provider_policy_memory_or_domain_decision_inside_go_edge_runtime',
            'do_not_capture_mic_screen_keychain_touchid_or_accessibility_without_kernel_policy_and_user_consent',
        ] as $token) {
            if (! str_contains($featurePlacementTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php: AP-201 feature placement runtime routing must be asserted [{$token}]";
            }
        }

        foreach ($this->scanEmbeddingServiceRuntimeBoundary() as $violation) {
            $violations[] = $violation;
        }

        foreach ($this->scanRuntimeBoundaryForbiddenLaravelImplementations() as $violation) {
            $violations[] = $violation;
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProductiveFailureGovernanceContract(): array
    {
        $flowPath = app_path('Services/Ai/Learning/ProductiveFailure/ProductiveFailureFlow.php');
        $commandPath = app_path('Console/Commands/AtlasProductiveFailureCommand.php');
        $featureTestPath = base_path('tests/Feature/Ai/Learning/AtlasProductiveFailureCommandTest.php');
        $apDocPath = base_path('docs/ap/AP-168-cognitive-productive-failure-flow.md');
        $staticScansDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');

        $flow = File::exists($flowPath) ? File::get($flowPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $staticScansDoc = File::exists($staticScansDocPath) ? File::get($staticScansDocPath) : '';
        $violations = [];

        foreach ([
            'atlas.cognitive.productive_failure_flow.v1',
            'productive_failure_topic_required',
            'productive_failure_storage_unavailable',
            'run_migrations_before_productive_failure',
            'ProductiveFailurePhase1Started',
            'ProductiveFailureCompleted',
            "'operator_opt_in_required' => true",
            "'specific_topic_required' => true",
            "'empty_topic_allowed' => false",
            "'auto_schedule_allowed' => false",
            "'passive_session_allowed' => false",
            "'random_frustration_allowed' => false",
            "'requires_prediction_error_delta' => true",
            "'transfer_test_review_required' => true",
            "'allowed_surfaces_now' => ['cli_explicit']",
        ] as $token) {
            if (! str_contains($flow, $token)) {
                $violations[] = "app/Services/Ai/Learning/ProductiveFailure/ProductiveFailureFlow.php: AP-168 governance/runtime contract must be preserved [{$token}]";
            }
        }

        foreach ([
            'productive_failure_topic_required',
            'productive_failure_storage_unavailable',
            'run_migrations_before_productive_failure',
        ] as $token) {
            if (! str_contains($command.$featureTest, $token)) {
                $violations[] = "app/Console/Commands/AtlasProductiveFailureCommand.php + tests: AP-168 CLI must expose explicit topic and storage-unavailable governance [{$token}]";
            }
        }

        foreach ([
            'test_command_runs_full_productive_failure_session_with_ledger_events',
            'test_completion_is_blocked_without_prediction_error_delta',
            'test_transfer_tests_action_returns_review_only_due_proposals',
            'test_productive_failure_requires_specific_topic_to_avoid_random_frustration',
            'test_productive_failure_blocks_when_storage_is_unavailable',
        ] as $token) {
            if (! str_contains($featureTest, $token)) {
                $violations[] = "tests/Feature/Ai/Learning/AtlasProductiveFailureCommandTest.php: AP-168 CLI/evidence/governance flow must be covered [{$token}]";
            }
        }

        foreach ([
            'Hardening note (2026-05-10)',
            'productive_failure_storage_unavailable',
            'run_migrations_before_productive_failure',
            'random_frustration_allowed=false',
            'requires_prediction_error_delta=true',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-168-cognitive-productive-failure-flow.md: AP-168 storage and prediction-error boundary must stay documented [{$token}]";
            }
        }

        foreach ([
            'AP-168 Productive Failure governance',
            'storage-backed',
            'productive_failure_sessions',
            'prediction-error delta',
        ] as $token) {
            if (! str_contains($staticScansDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/kernel/static-scans.md: AP-168 static scan must be documented [{$token}]";
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanPersonalWorkedExamplePrivacyContract(): array
    {
        $redactorPath = app_path('Services/Ai/Learning/PersonalWorkedExample/PersonalWorkedExamplePrivacyRedactor.php');
        $extractorPath = app_path('Services/Ai/Learning/PersonalWorkedExample/PersonalWorkedExampleExtractor.php');
        $privacyGatePath = app_path('Services/Ai/Kernel/Gates/PersonalWorkedExamplePrivacySafeGate.php');
        $featureTestPath = base_path('tests/Feature/Ai/Learning/AtlasWorkedExamplePersonalExtractionCommandTest.php');
        $redactorTestPath = base_path('tests/Unit/Ai/Learning/PersonalWorkedExample/PersonalWorkedExamplePrivacyRedactorTest.php');
        $apDocPath = base_path('docs/ap/AP-169-cognitive-personal-worked-examples-generator.md');
        $staticScansDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');

        $redactor = File::exists($redactorPath) ? File::get($redactorPath) : '';
        $extractor = File::exists($extractorPath) ? File::get($extractorPath) : '';
        $privacyGate = File::exists($privacyGatePath) ? File::get($privacyGatePath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $redactorTest = File::exists($redactorTestPath) ? File::get($redactorTestPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $staticScansDoc = File::exists($staticScansDocPath) ? File::get($staticScansDocPath) : '';
        $violations = [];

        foreach ([
            'source_metadata',
            'quality_signals',
            'redactValue',
            '[redacted_email]',
            '[redacted_secret]',
            'provider_safe',
        ] as $token) {
            if (! str_contains($redactor, $token)) {
                $violations[] = "app/Services/Ai/Learning/PersonalWorkedExample/PersonalWorkedExamplePrivacyRedactor.php: AP-169 must redact metadata and quality signals before persistence [{$token}]";
            }
        }

        foreach ([
            'raw_content_in_ledger',
            'source_ref_hash',
            'PersonalWorkedExampleDiscardedDuplicate',
            'read_only_existing_record_preserved',
        ] as $token) {
            if (! str_contains($extractor, $token)) {
                $violations[] = "app/Services/Ai/Learning/PersonalWorkedExample/PersonalWorkedExampleExtractor.php: AP-169 Ledger summaries must stay sanitized and duplicate-safe [{$token}]";
            }
        }

        foreach ([
            'privacy_secrets_detected',
            'privacy_class_3_requires_redaction',
            'personal_worked_example_privacy_safe',
            '[a-z0-9._-]{12,}',
        ] as $token) {
            if (! str_contains($privacyGate, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Gates/PersonalWorkedExamplePrivacySafeGate.php: AP-169 privacy gate must block unredacted secrets and sensitive privacy classes [{$token}]";
            }
        }

        foreach ([
            'storedMetadata',
            'storedSignals',
            'commitsecret123456',
            'assertStringNotContainsString',
        ] as $token) {
            if (! str_contains($featureTest, $token)) {
                $violations[] = "tests/Feature/Ai/Learning/AtlasWorkedExamplePersonalExtractionCommandTest.php: AP-169 e2e must prove extraction read model is sanitized [{$token}]";
            }
        }

        foreach ([
            'source_metadata',
            'quality_signals',
            'secret-quality-token123',
            'assertStringContainsString',
        ] as $token) {
            if (! str_contains($redactorTest, $token)) {
                $violations[] = "tests/Unit/Ai/Learning/PersonalWorkedExample/PersonalWorkedExamplePrivacyRedactorTest.php: AP-169 redactor unit test must cover metadata and quality signals [{$token}]";
            }
        }

        foreach ([
            'Hardening note (2026-05-10)',
            'source_metadata',
            'quality_signals',
            'texto cru de commit/Feynman/decisao nao pode',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-169-cognitive-personal-worked-examples-generator.md: AP-169 privacy hardening boundary must stay documented [{$token}]";
            }
        }

        foreach ([
            'AP-169 Personal Worked Examples privacy',
            'read model de extracao',
        ] as $token) {
            if (! str_contains($staticScansDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/kernel/static-scans.md: AP-169 static scan must be documented [{$token}]";
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanPredictiveFailureGovernanceContract(): array
    {
        $flowPath = app_path('Services/Ai/Learning/PredictiveFailure/PredictiveFailureFlow.php');
        $commandPath = app_path('Console/Commands/AtlasPredictCommand.php');
        $calibrationGatePath = app_path('Services/Ai/Kernel/Gates/PredictiveFailureCalibrationBandGate.php');
        $safetyGatePath = app_path('Services/Ai/Kernel/Gates/PredictiveFailureSafetyGate.php');
        $featureTestPath = base_path('tests/Feature/Ai/Learning/AtlasPredictCommandTest.php');
        $gateTestPath = base_path('tests/Unit/Ai/Kernel/Gates/PredictiveFailureGateTest.php');
        $apDocPath = base_path('docs/ap/AP-170-cognitive-predictive-failure-insertion.md');
        $briefingPath = base_path('docs/engineering-knowledge-base/cognitive/implementation-briefing.md');
        $staticScansDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');

        $flow = File::exists($flowPath) ? File::get($flowPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $calibrationGate = File::exists($calibrationGatePath) ? File::get($calibrationGatePath) : '';
        $safetyGate = File::exists($safetyGatePath) ? File::get($safetyGatePath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $gateTest = File::exists($gateTestPath) ? File::get($gateTestPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $briefing = File::exists($briefingPath) ? File::get($briefingPath) : '';
        $staticScansDoc = File::exists($staticScansDocPath) ? File::get($staticScansDocPath) : '';
        $violations = [];

        foreach ([
            'atlas.cognitive.predictive_failure.flow.v1',
            'atlas.cognitive.predictive_failure.governance.v1',
            "'operator_opt_in_required' => true",
            "'specific_target_required' => true",
            "'empty_subject_allowed' => false",
            "'auto_schedule_allowed' => false",
            "'passive_insertion_allowed' => false",
            "'random_frustration_allowed' => false",
            "'daily_plan_auto_insert_allowed' => false",
            "'requires_calibration_band_gate' => true",
            "'requires_safety_gate' => true",
            "'requires_outcome_tracking' => true",
            "'allowed_surfaces_now' => ['cli_explicit']",
            'predictive_failure_subject_required',
            'predictive_failure_storage_unavailable',
            'run_migrations_before_predictive_failure',
            'PredictiveFailureInserted',
            'PredictiveFailureInsertionSkipped',
            'PredictiveFailurePriorUpdated',
        ] as $token) {
            if (! str_contains($flow, $token)) {
                $violations[] = "app/Services/Ai/Learning/PredictiveFailure/PredictiveFailureFlow.php: AP-170 governance/runtime contract must be preserved [{$token}]";
            }
        }

        foreach ([
            'predictive_failure_subject_required',
            'predictive_failure_insertion_id_required',
            'unknown_predict_action',
            'PredictiveFailureFlow::governanceContract()',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasPredictCommand.php: AP-170 CLI must require explicit target and expose governance on invalid input [{$token}]";
            }
        }

        foreach ([
            'predictive_failure_calibration_band',
            'predictive_failure_too_easy_outside_zone',
            'predictive_failure_too_hard_outside_zone',
            'predictive_failure_calibration_band_sweet',
        ] as $token) {
            if (! str_contains($calibrationGate, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Gates/PredictiveFailureCalibrationBandGate.php: AP-170 calibration band gate must remain executable [{$token}]";
            }
        }

        foreach ([
            'predictive_failure_safety',
            'predictive_failure_blocked_high_cognitive_load',
            'predictive_failure_privacy_class_too_high',
            'predictive_failure_blocked_stress_state',
            'isSensitivePrivacyClass',
        ] as $token) {
            if (! str_contains($safetyGate, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Gates/PredictiveFailureSafetyGate.php: AP-170 safety gate must remain executable [{$token}]";
            }
        }

        foreach ([
            'test_predict_failure_insert_resolve_and_metrics_flow',
            'test_predict_failure_is_blocked_under_high_cognitive_load',
            'test_predict_failure_requires_specific_target_to_avoid_random_frustration',
            'test_predict_resolve_requires_valid_insertion_id',
            'test_predictive_failure_flow_rejects_empty_target_even_outside_cli',
            'test_predictive_failure_flow_blocks_when_storage_is_unavailable',
            'PredictiveFailureInserted',
            'PredictiveFailureOutcomeFailure',
            'PredictiveFailureCalibrationComputed',
            'operator_opt_in_required',
            'daily_plan_auto_insert_allowed',
        ] as $token) {
            if (! str_contains($featureTest, $token)) {
                $violations[] = "tests/Feature/Ai/Learning/AtlasPredictCommandTest.php: AP-170 CLI/evidence/governance flow must be covered [{$token}]";
            }
        }

        foreach ([
            'test_calibration_gate_blocks_outside_sweet_band_and_passes_sweet_band',
            'test_safety_gate_blocks_high_load_and_sensitive_privacy',
        ] as $token) {
            if (! str_contains($gateTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Gates/PredictiveFailureGateTest.php: AP-170 gates must be covered [{$token}]";
            }
        }

        foreach ([
            'Status: implemented_partial',
            'alvo explicito obrigatorio',
            'Service-level guard tambem exige alvo explicito',
            'predictive_failure_storage_unavailable',
            'random_frustration_allowed=false',
            'daily-plan/on-off/UX App-Mobile-Voice',
            'requires_rivals_learning_validation_before_default=true',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-170-cognitive-predictive-failure-insertion.md: AP-170 must document current partial boundary [{$token}]";
            }
        }

        foreach ([
            'AP-170',
            'implemented_partial',
            'daily-plan/UX/KG maduro futuros',
        ] as $token) {
            if (! str_contains($briefing, $token)) {
                $violations[] = "docs/engineering-knowledge-base/cognitive/implementation-briefing.md: AP-170 status must stay visible to AI implementers [{$token}]";
            }
        }

        foreach ([
            'AP-170 Predictive Failure governance',
            'alvo explicito',
            'random frustration',
            'daily-plan/UX/KG maduro',
        ] as $token) {
            if (! str_contains($staticScansDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/kernel/static-scans.md: AP-170 static scan must be documented [{$token}]";
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEmbeddingServiceRuntimeBoundary(): array
    {
        $violations = [];
        $embeddingServicePath = app_path('Services/Semantic/EmbeddingService.php');
        $matrixPath = base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');
        $runtimeBoundaryTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/KernelArchitectureStaticScannerRuntimeLanguageBoundaryTest.php');

        $embeddingService = File::exists($embeddingServicePath) ? File::get($embeddingServicePath) : '';
        $matrix = File::exists($matrixPath) ? File::get($matrixPath) : '';
        $runtimeBoundaryTest = File::exists($runtimeBoundaryTestPath) ? File::get($runtimeBoundaryTestPath) : '';

        foreach ([
            'SemanticRagRuntimeClient',
            'embedWithSemanticRag',
            'embedWithOpenAi',
            'No real embedding provider available',
            'crc32 hash fake was retired',
        ] as $token) {
            if (! str_contains($embeddingService, $token)) {
                $violations[] = "app/Services/Semantic/EmbeddingService.php: AP-201 requires EmbeddingService to remain a real-provider adapter or explicit failure boundary [{$token}]";
            }
        }

        foreach ([
            'EmbeddingService` real-provider adapter',
            'nao promover para Vector RAG, Graph RAG, reranker ou clustering',
            'FAISS/Chroma/LangGraph/NetworkX/Pandas/Polars/scikit/reranker/clustering',
        ] as $token) {
            if (! str_contains($matrix, $token)) {
                $violations[] = "docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md: AP-201 must preserve EmbeddingService boundary in the scaffold matrix [{$token}]";
            }
        }

        foreach ([
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_laravel_app',
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_semantic_adapter',
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_controller',
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_job',
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_command',
            'test_runtime_language_boundary_scan_flags_direct_go_runtime_inside_controller',
            'test_runtime_language_boundary_scan_flags_direct_swift_native_runtime_inside_command',
            'test_runtime_language_boundary_scan_flags_laravel_process_facade_runtime_escape',
            'test_runtime_language_boundary_scan_flags_symfony_process_runtime_escapes',
            'test_runtime_language_boundary_scan_preserves_embedding_service_as_real_provider_adapter',
            'ForbiddenChromaRegression.php',
            'ForbiddenLangGraphController.php',
            'ForbiddenPandasJob.php',
            'ForbiddenNumpyCommand.php',
            'ForbiddenGoEdgeController.php',
            'ForbiddenSwiftNativeCommand.php',
            'ForbiddenLaravelProcessRuntimeController.php',
            'ForbiddenSymfonyProcessGoController.php',
            'ForbiddenSymfonyProcessSwiftCommand.php',
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_telemetry',
            'test_runtime_language_boundary_scan_preserves_real_telemetry_stats_and_runtime_clients',
            'ForbiddenNumpyTelemetryEngine.php',
        ] as $token) {
            if (! str_contains($runtimeBoundaryTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/KernelArchitectureStaticScannerRuntimeLanguageBoundaryTest.php: AP-201 must cover semantic adapter regressions [{$token}]";
            }
        }

        foreach ([
            'faiss',
            'chromadb',
            'llama_index',
            'langgraph',
            'networkx',
            'pandas',
            'polars',
            'sklearn',
            'torch',
            'tensorflow',
            // Provider model identifiers may contain "transformers" (for
            // example a Hugging Face model slug). Heavy runtime detection is
            // handled by the import/process scanners below, not by matching a
            // provider adapter's model name.
            'similaritySearch',
            'nearestNeighbors',
            'GraphRag',
            'VectorRag',
            'Reranker',
            'Clusterer',
        ] as $token) {
            if (str_contains($embeddingService, $token)) {
                $violations[] = "app/Services/Semantic/EmbeddingService.php: forbidden AP-201 token [{$token}]. Heavy RAG/ML belongs in python_ai_data behind DecisionReceipt.";
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    /**
     * @return array<int,string>
     */
    private function scanRuntimeBoundaryForbiddenLaravelImplementations(): array
    {
        // Curated allow-list of Laravel *implementation* subtrees, NOT the whole
        // app/Services/Ai tree. Scanning the full tree is deliberately rejected:
        // several Services/Ai subtrees legitimately NAME the forbidden tokens as
        // data and would self-flag (false positives) —
        //   - Services/Ai/Kernel/Architecture/* (this scanner + the boundary
        //     catalog/report/placement services literally enumerate faiss/numpy/
        //     CoreML/FaissIndex as patterns and documentation);
        //   - Services/Ai/RuntimeBoundary/* (the sanctioned PHP->Python bridge;
        //     its *GraphRagContract.php interface names match laravel_heavy_rag_engine,
        //     while *RuntimeClient.php spawns a GENERIC python entrypoint
        //     `new Process([venvPython, main.py, manifest])` with no heavy-lib
        //     literal in the array — correctly NOT matched);
        //   - Services/Ai/Programming/* + Services/Ai/Mobile/* (delegating adapters
        //     named *GraphRag*/*Reranker* match by name, not by hand-rolled math).
        // Telemetry IS included: hand-rolled KS / Mann-Kendall / CUSUM / EWMA /
        // Wilson math lived there undetected until it was moved to
        // runtimes/python/stats_engine behind StatsEngineRuntimeClient. Any NEW
        // implementation subtree that could host heavy data/AI numerics belongs
        // here; meta/boundary/catalog subtrees (above) must not be added.
        $roots = [
            app_path('Services/Ai/Context'),
            app_path('Services/Ai/Learning'),
            app_path('Services/Ai/Domain'),
            app_path('Services/Ai/Memory'),
            app_path('Services/Ai/Provider'),
            app_path('Services/Ai/Surface'),
            app_path('Services/Ai/Telemetry'),
            app_path('Services/Semantic'),
            app_path('Http/Controllers'),
            app_path('Jobs'),
            app_path('Console/Commands'),
        ];

        $patterns = [
            'python_import_faiss' => '/\b(import|from)\s+(faiss|chromadb|llama_index|langgraph|networkx|pandas|polars|numpy|sklearn|torch|tensorflow|transformers)\b/i',
            'process_exec_heavy_ai_runtime' => '/\b(shell_exec|exec|passthru|proc_open)\s*\([^;]*(faiss|chromadb|llama_index|langgraph|networkx|pandas|polars|numpy|sklearn|torch|tensorflow|transformers)/is',
            'laravel_process_heavy_ai_runtime' => '/\bProcess::(?:run|start|forever|pipe)\s*\([^;]*(faiss|chromadb|llama_index|langgraph|networkx|pandas|polars|numpy|sklearn|torch|tensorflow|transformers)/is',
            'symfony_process_shell_heavy_ai_runtime' => '/\bProcess::fromShellCommandline\s*\([^;]*(faiss|chromadb|llama_index|langgraph|networkx|pandas|polars|numpy|sklearn|torch|tensorflow|transformers)/is',
            'symfony_process_array_heavy_ai_runtime' => '/\bnew\s+Process\s*\(\s*\[[^\]]*(python|python3)[^\]]*(faiss|chromadb|llama_index|langgraph|networkx|pandas|polars|numpy|sklearn|torch|tensorflow|transformers)[^\]]*\]/is',
            'laravel_heavy_rag_class' => '/\bnew\s+(FaissIndex|ChromaCollection|LlamaIndex|LangGraph|NetworkX|PandasDataFrame|PolarsDataFrame|TorchModel|TensorFlowModel|TransformersPipeline)\b/i',
            'direct_heavy_rag_symbol' => '/\b(FaissIndex|ChromaCollection|LlamaIndexRunner|LangGraphRunner|NetworkXGraph|PandasDataFrame|PolarsDataFrame|TorchTensor|TensorFlowModel|TransformersPipeline)\b/',
            'laravel_heavy_rag_engine' => '/\b(class|function)\s+\w*(GraphRag|VectorRag|Rerank|Faiss|Chroma|LangGraph|LlamaIndex)\w*\b/i',
            'process_exec_go_edge_runtime' => '/\b(shell_exec|exec|passthru|proc_open)\s*\([^;]*(go\s+(run|build|test)|nats|kafka|webhook-ingestor|postback-ingestor)/is',
            'laravel_process_go_edge_runtime' => '/\bProcess::(?:run|start|forever|pipe|fromShellCommandline)\s*\([^;]*(go\s+(run|build|test)|nats|kafka|webhook-ingestor|postback-ingestor)/is',
            'symfony_process_array_go_edge_runtime' => '/\bnew\s+Process\s*\(\s*\[[^\]]*go[^\]]*(run|build|test|nats|kafka|webhook-ingestor|postback-ingestor)[^\]]*\]/is',
            'process_exec_swift_native_runtime' => '/\b(shell_exec|exec|passthru|proc_open)\s*\([^;]*(swift\s+(run|build|test)|swiftc|ScreenCaptureKit|FSEvents|AVAudioEngine|CoreML|NSWorkspace)/is',
            'laravel_process_swift_native_runtime' => '/\bProcess::(?:run|start|forever|pipe|fromShellCommandline)\s*\([^;]*(swift\s+(run|build|test)|swiftc|ScreenCaptureKit|FSEvents|AVAudioEngine|CoreML|NSWorkspace)/is',
            'symfony_process_array_swift_native_runtime' => '/\bnew\s+Process\s*\(\s*\[[^\]]*(swift|swiftc|ScreenCaptureKit|FSEvents|AVAudioEngine|CoreML|NSWorkspace)[^\]]*\]/is',
            'direct_swift_native_symbol' => '/\b(ScreenCaptureKit|FSEvents|AVAudioEngine|CoreML|NSWorkspace|SFSpeechRecognizer|LAContext|SecKeychain)\b/',
        ];

        $violations = [];

        foreach ($roots as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getRealPath() ?: $file->getPathname();
                $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                $contents = File::get($path);

                foreach ($patterns as $patternId => $pattern) {
                    if (preg_match($pattern, $contents) === 1) {
                        $violations[] = "{$relativePath}: forbidden runtime language boundary violation [{$patternId}]. Heavy AI/Data runtime belongs in python_ai_data, go_edge, or swift_native_mac behind Kernel DecisionReceipt.";
                    }
                }
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    /**
     * @param  array<int,string>  $tokens
     * @param  array<int,string>  $ignoredPaths
     * @return array<int,string>
     */
    private function scanPhpFilesForForbiddenTokens(string $directory, array $tokens, array $ignoredPaths = []): array
    {
        return $this->primitives->scanPhpFilesForForbiddenTokens($directory, $tokens, $ignoredPaths);
    }

    /**
     * @param  array<int,string>  $tokens
     * @return array<int,string>
     */
    private function missingTokenViolations(string $content, array $tokens, string $messagePrefix): array
    {
        return $this->primitives->missingTokenViolations($content, $tokens, $messagePrefix);
    }

    private function fileContents(string $path): string
    {
        return $this->primitives->fileContents($path);
    }

    /**
     * @return array<int,string>
     */
    private function scanReplayReportInputContract(): array
    {
        $servicePath = app_path('Services/Ai/Kernel/Evidence/KernelReplayReportInput.php');
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $commandPaths = [
            app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php'),
            app_path('Console/Commands/AtlasAiSloCommand.php'),
            app_path('Console/Commands/AtlasAiKernelPipelineReportCommand.php'),
            app_path('Console/Commands/AtlasAiRepairReportCommand.php'),
        ];
        $controllerPaths = [
            app_path('Http/Controllers/AtlasAiSelfImprovementScheduleReportController.php'),
            app_path('Http/Controllers/AtlasAiSloController.php'),
            app_path('Http/Controllers/AtlasAiKernelPipelineReportController.php'),
            app_path('Http/Controllers/AtlasAiRepairReportController.php'),
        ];
        $testPath = base_path('tests/Unit/Ai/Kernel/KernelReplayReportInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'final class KernelReplayReportInput',
            'public const DEFAULT_WINDOW_HOURS = 24',
            'public const MAX_WINDOW_HOURS = 720',
            'public function hours(mixed $value): int',
            'public function scalarFilters(array $input, array $allowed): array',
            'public function aliasedScalarFilters(array $input, array $aliases): array',
            'return max(1, min(self::MAX_WINDOW_HOURS, (int) $value))',
            "trim((string) \$value) !== ''",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelReplayReportInput.php: replay report input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly KernelReplayReportInput $replayInput',
            '$this->replayInput->hours',
            '$this->replayInput->scalarFilters',
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP replay tools must consume KernelReplayReportInput [{$token}]";
            }
        }

        foreach (array_merge($commandPaths, $controllerPaths) as $path) {
            $contents = File::exists($path) ? File::get($path) : '';
            if (! str_contains($contents, 'KernelReplayReportInput')) {
                $violations[] = "{$path}: replay report surface must consume KernelReplayReportInput";
            }
        }

        foreach ([
            'test_hours_normalizes_window_with_canonical_limits',
            'test_scalar_filters_trim_values_and_drop_empty_or_non_scalar_values',
            'test_aliased_scalar_filters_use_first_non_empty_alias',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/KernelReplayReportInputTest.php: replay report input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'replay report input contract',
            'AP-66',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe replay report input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanObservabilityReplayInputContract(): array
    {
        $controllerPath = app_path('Http/Controllers/AiObservabilityController.php');
        $testPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;',
            'KernelReplayReportInput $replayInput',
            "\$hours = \$replayInput->hours(\$data['hours'] ?? null)",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: observability must use shared replay input contract [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_uses_default_replay_window_contract',
            'CarbonImmutable::parse',
            '$this->assertEqualsWithDelta(1440',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: observability replay window contract must be covered [{$token}]";
            }
        }

        foreach ([
            'observability replay input contract',
            'AP-67',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe observability replay input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanReplayReportValidationLimitContract(): array
    {
        $controllerPaths = [
            app_path('Http/Controllers/AiObservabilityController.php'),
            app_path('Http/Controllers/AtlasAiSelfImprovementScheduleReportController.php'),
            app_path('Http/Controllers/AtlasAiSloController.php'),
            app_path('Http/Controllers/AtlasAiKernelPipelineReportController.php'),
            app_path('Http/Controllers/AtlasAiRepairReportController.php'),
        ];
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        foreach ($controllerPaths as $path) {
            $contents = File::exists($path) ? File::get($path) : '';

            if (! str_contains($contents, "'between:1,'.KernelReplayReportInput::MAX_WINDOW_HOURS")) {
                $violations[] = "{$path}: replay report API hours validation must use KernelReplayReportInput::MAX_WINDOW_HOURS";
            }

            if (str_contains($contents, "'between:1,720'") || str_contains($contents, '"between:1,720"')) {
                $violations[] = "{$path}: replay report API hours validation must not duplicate literal between:1,720";
            }
        }

        $docs = $this->kernelDocumentationCorpus();
        foreach ([
            'replay report validation limit contract',
            'AP-68',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe replay report validation limit contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanTelemetryWindowInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Telemetry/AiTelemetryWindowInput.php');
        $controllerPath = app_path('Http/Controllers/AiTelemetryMetricsController.php');
        $healthCommandPath = app_path('Console/Commands/AiTelemetryHealthCommand.php');
        $rollupCommandPath = app_path('Console/Commands/AiTelemetryRollupCommand.php');
        $testPath = base_path('tests/Unit/Ai/Telemetry/AiTelemetryWindowInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $healthCommand = File::exists($healthCommandPath) ? File::get($healthCommandPath) : '';
        $rollupCommand = File::exists($rollupCommandPath) ? File::get($rollupCommandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'final class AiTelemetryWindowInput',
            'public const DEFAULT_WINDOW_HOURS = 24',
            'public const DEFAULT_COST_RATE_WINDOW_HOURS = 168',
            'public const MAX_WINDOW_HOURS = 720',
            'return max(1, min(self::MAX_WINDOW_HOURS, (int) $value))',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Telemetry/AiTelemetryWindowInput.php: telemetry window input contract is missing [{$token}]";
            }
        }

        foreach ([
            'AiTelemetryWindowInput $telemetryWindow',
            "'between:1,'.AiTelemetryWindowInput::MAX_WINDOW_HOURS",
            '$telemetryWindow->hours($data[\'hours\'] ?? null)',
            'AiTelemetryWindowInput::DEFAULT_COST_RATE_WINDOW_HOURS',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AiTelemetryMetricsController.php: telemetry APIs must use shared window input [{$token}]";
            }
        }

        foreach ([
            'AiTelemetryWindowInput $telemetryWindow',
            '$telemetryWindow->hours($this->option(\'hours\'))',
        ] as $token) {
            if (! str_contains($healthCommand, $token)) {
                $violations[] = "app/Console/Commands/AiTelemetryHealthCommand.php: telemetry health command must use shared window input [{$token}]";
            }

            if (! str_contains($rollupCommand, $token)) {
                $violations[] = "app/Console/Commands/AiTelemetryRollupCommand.php: telemetry rollup command must use shared window input [{$token}]";
            }
        }

        foreach ([
            'test_hours_normalizes_telemetry_window_with_canonical_limits',
            'AiTelemetryWindowInput::MAX_WINDOW_HOURS',
            'AiTelemetryWindowInput::DEFAULT_COST_RATE_WINDOW_HOURS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Telemetry/AiTelemetryWindowInputTest.php: telemetry window input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'telemetry window input contract',
            'AP-72',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe telemetry window input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRuntimeBudgetWindowContract(): array
    {
        $settingsPath = app_path('Services/Ai/AtlasAiRuntimeSettings.php');
        $budgetServicePath = app_path('Services/Ai/AiRuntimeBudgetService.php');
        $policyServicePath = app_path('Services/Ai/AtlasAiPolicyService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasAiRuntimeSettingsTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $settings = File::exists($settingsPath) ? File::get($settingsPath) : '';
        $budgetService = File::exists($budgetServicePath) ? File::get($budgetServicePath) : '';
        $policyService = File::exists($policyServicePath) ? File::get($policyServicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'public const DEFAULT_BUDGET_WINDOW_HOURS = 24',
            'public const MAX_BUDGET_WINDOW_HOURS = 168',
            'public function normalizeBudgetWindowHours(mixed $value): int',
            'return max(1, min(self::MAX_BUDGET_WINDOW_HOURS, (int) $value))',
        ] as $token) {
            if (! str_contains($settings, $token)) {
                $violations[] = "app/Services/Ai/AtlasAiRuntimeSettings.php: runtime budget window contract is incomplete [{$token}]";
            }
        }

        if (str_contains($settings, 'min(168') || str_contains($settings, '?? 24)')) {
            $violations[] = 'app/Services/Ai/AtlasAiRuntimeSettings.php: runtime budget window must not duplicate numeric window limits';
        }

        foreach ([
            'AtlasAiRuntimeSettings::DEFAULT_BUDGET_WINDOW_HOURS',
            'atlas.runtime_budget.governance_contract.v1',
            "'autonomy_escalation_allowed' => false",
            "'budget_limit_auto_raise_allowed' => false",
            "'requires_human_review_for_limit_change' => true",
            "'requires_decision_receipt_for_limit_change' => true",
            'bypass_budget_block',
        ] as $token) {
            if (! str_contains($budgetService, $token)) {
                $violations[] = "app/Services/Ai/AiRuntimeBudgetService.php: runtime budget payload must use shared budget window default [{$token}]";
            }

            if (! str_contains($policyService, $token)) {
                $violations[] = "app/Services/Ai/AtlasAiPolicyService.php: effective policy must use shared budget window default [{$token}]";
            }
        }

        foreach ([
            'test_budget_window_hours_uses_explicit_policy_contract',
            'AtlasAiRuntimeSettings::MAX_BUDGET_WINDOW_HOURS',
            'AtlasAiRuntimeSettings::DEFAULT_BUDGET_WINDOW_HOURS',
            'atlas.runtime_budget.governance_contract.v1',
            'governance_contract.requires_decision_receipt_for_limit_change',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasAiRuntimeSettingsTest.php: runtime budget window contract must be covered [{$token}]";
            }
        }

        foreach ([
            'runtime budget window contract',
            'AP-73',
            'atlas.runtime_budget.governance_contract.v1',
            'autonomy_escalation_allowed=false',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe runtime budget window contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanTelemetryListLimitContract(): array
    {
        $inputPath = app_path('Services/Ai/Telemetry/AiTelemetryWindowInput.php');
        $controllerPath = app_path('Http/Controllers/AiTelemetryMetricsController.php');
        $unitTestPath = base_path('tests/Unit/Ai/Telemetry/AiTelemetryWindowInputTest.php');
        $featureTestPath = base_path('tests/Feature/AiTelemetryMetricsTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'public const DEFAULT_SUMMARY_LIMIT = 25',
            'public const MAX_SUMMARY_LIMIT = 100',
            'public const DEFAULT_COST_RATE_LIMIT = 100',
            'public const MAX_COST_RATE_LIMIT = 200',
            'public const DEFAULT_OUTCOME_LIMIT = 50',
            'public const MAX_OUTCOME_LIMIT = 200',
            'public function limit(mixed $value, int $default, int $max): int',
            'return max(1, min($max, (int) $value))',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Telemetry/AiTelemetryWindowInput.php: telemetry list limit contract is incomplete [{$token}]";
            }
        }

        foreach ([
            "'between:1,'.AiTelemetryWindowInput::MAX_SUMMARY_LIMIT",
            "'between:1,'.AiTelemetryWindowInput::MAX_COST_RATE_LIMIT",
            "'between:1,'.AiTelemetryWindowInput::MAX_OUTCOME_LIMIT",
            'AiTelemetryWindowInput::DEFAULT_SUMMARY_LIMIT',
            'AiTelemetryWindowInput::DEFAULT_COST_RATE_LIMIT',
            'AiTelemetryWindowInput::DEFAULT_OUTCOME_LIMIT',
            '$telemetryWindow->limit(',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AiTelemetryMetricsController.php: telemetry list APIs must use shared limit input [{$token}]";
            }
        }

        foreach ([
            'test_limit_normalizes_telemetry_list_limits_with_canonical_caps',
            'AiTelemetryWindowInput::MAX_SUMMARY_LIMIT',
            'AiTelemetryWindowInput::MAX_OUTCOME_LIMIT',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Telemetry/AiTelemetryWindowInputTest.php: telemetry list limit contract must be covered [{$token}]";
            }
        }

        foreach ([
            'test_telemetry_list_apis_use_canonical_limit_contracts',
            'AiTelemetryWindowInput::MAX_SUMMARY_LIMIT',
            'AiTelemetryWindowInput::MAX_COST_RATE_LIMIT',
            'AiTelemetryWindowInput::MAX_OUTCOME_LIMIT',
        ] as $token) {
            if (! str_contains($featureTest, $token)) {
                $violations[] = "tests/Feature/AiTelemetryMetricsTest.php: telemetry list API limits must be covered [{$token}]";
            }
        }

        foreach ([
            'telemetry list limit contract',
            'AP-76',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe telemetry list limit contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanOpenBrainMcpInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Kernel/Mcp/OpenBrainMcpInput.php');
        $servicePath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/OpenBrainMcpInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'final class OpenBrainMcpInput',
            'public const DEFAULT_CODE_LIMIT = 20',
            'public const MAX_CODE_LIMIT = 100',
            'public const DEFAULT_DOCS_LIMIT = 10',
            'public const MAX_DOCS_LIMIT = 50',
            'public const DEFAULT_RECENT_CHANGES_LIMIT = 50',
            'public const MAX_RECENT_CHANGES_LIMIT = 200',
            'public const DEFAULT_DECISION_LIMIT = 5',
            'public const MAX_DECISION_LIMIT = 20',
            'public const DEFAULT_SYMBOLS_LIMIT = 50',
            'public const MAX_SYMBOLS_LIMIT = 200',
            'public function codeLimit(',
            'public function docsLimit(',
            'public function recentChangesLimit(',
            'public function decisionLimit(',
            'public function symbolsLimit(',
            'public function contextMemoryLimit(',
            'public function contextCodeLimit(',
            'public function contextDocsLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Mcp/OpenBrainMcpInput.php: Open Brain MCP input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly OpenBrainMcpInput $mcpInput',
            '$this->mcpInput->codeLimit(',
            '$this->mcpInput->docsLimit(',
            '$this->mcpInput->recentChangesLimit(',
            '$this->mcpInput->decisionLimit(',
            '$this->mcpInput->symbolsLimit(',
            '$this->mcpInput->contextMemoryLimit(',
            '$this->mcpInput->contextCodeLimit(',
            '$this->mcpInput->contextDocsLimit(',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP tools must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_limits_normalize_mcp_tool_windows_with_canonical_caps',
            'OpenBrainMcpInput::MAX_CODE_LIMIT',
            'OpenBrainMcpInput::MAX_CONTEXT_DOCS_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/OpenBrainMcpInputTest.php: Open Brain MCP input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'open brain mcp input contract',
            'AP-78',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe Open Brain MCP input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanMemoryQueryInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Memory/MemoryQueryInput.php');
        $registryPath = app_path('Services/Ai/AtlasMemoryRegistryService.php');
        $verbatimPath = app_path('Services/Ai/AtlasVerbatimMemoryService.php');
        $governancePath = app_path('Services/Ai/AtlasMemoryGovernanceService.php');
        $privacyPath = app_path('Services/Ai/AtlasMemoryPrivacyService.php');
        $deltaPromotionPath = app_path('Services/Ai/AtlasMemoryDeltaPromotionService.php');
        $learningPromotionPath = app_path('Services/Ai/AtlasMemoryLearningPromotionService.php');
        $maintenancePath = app_path('Services/Ai/AtlasMemoryMaintenanceService.php');
        $reviewQueuePath = app_path('Services/Ai/AtlasMemoryReviewQueueService.php');
        $qualityPath = app_path('Services/Ai/AtlasMemoryQualityService.php');
        $listCommandPath = app_path('Console/Commands/AtlasMemoryListCommand.php');
        $verbatimCommandPath = app_path('Console/Commands/AtlasMemoryVerbatimCommand.php');
        $relationsCommandPath = app_path('Console/Commands/AtlasMemoryRelationsCommand.php');
        $reviewQueueCommandPath = app_path('Console/Commands/AtlasMemoryReviewQueueCommand.php');
        $governanceCommandPath = app_path('Console/Commands/AtlasMemoryGovernanceCommand.php');
        $privacyCommandPath = app_path('Console/Commands/AtlasMemoryPrivacyCommand.php');
        $testPath = base_path('tests/Unit/Ai/Memory/MemoryQueryInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $registry = File::exists($registryPath) ? File::get($registryPath) : '';
        $verbatim = File::exists($verbatimPath) ? File::get($verbatimPath) : '';
        $governance = File::exists($governancePath) ? File::get($governancePath) : '';
        $privacy = File::exists($privacyPath) ? File::get($privacyPath) : '';
        $deltaPromotion = File::exists($deltaPromotionPath) ? File::get($deltaPromotionPath) : '';
        $learningPromotion = File::exists($learningPromotionPath) ? File::get($learningPromotionPath) : '';
        $maintenance = File::exists($maintenancePath) ? File::get($maintenancePath) : '';
        $reviewQueue = File::exists($reviewQueuePath) ? File::get($reviewQueuePath) : '';
        $quality = File::exists($qualityPath) ? File::get($qualityPath) : '';
        $listCommand = File::exists($listCommandPath) ? File::get($listCommandPath) : '';
        $verbatimCommand = File::exists($verbatimCommandPath) ? File::get($verbatimCommandPath) : '';
        $relationsCommand = File::exists($relationsCommandPath) ? File::get($relationsCommandPath) : '';
        $reviewQueueCommand = File::exists($reviewQueueCommandPath) ? File::get($reviewQueueCommandPath) : '';
        $governanceCommand = File::exists($governanceCommandPath) ? File::get($governanceCommandPath) : '';
        $privacyCommand = File::exists($privacyCommandPath) ? File::get($privacyCommandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'final class MemoryQueryInput',
            'public const DEFAULT_REGISTRY_LIMIT = 50',
            'public const DEFAULT_RELEVANT_LIMIT = 25',
            'public const DEFAULT_VERBATIM_LIMIT = 50',
            'public const DEFAULT_VERBATIM_CONTEXT_LIMIT = 12',
            'public const DEFAULT_GOVERNANCE_SCAN_LIMIT = 200',
            'public const DEFAULT_RELATION_LIMIT = 50',
            'public const DEFAULT_PROMOTION_LIMIT = 50',
            'public const DEFAULT_REVIEW_QUEUE_LIMIT = 50',
            'public const DEFAULT_QUALITY_HISTORY_DAYS = 30',
            'public const MAX_MEMORY_LIMIT = 200',
            'public const MAX_SCAN_LIMIT = 500',
            'public const MAX_QUALITY_HISTORY_DAYS = 365',
            'public function registryLimit(',
            'public function relevantLimit(',
            'public function verbatimLimit(',
            'public function verbatimContextLimit(',
            'public function governanceScanLimit(',
            'public function relationLimit(',
            'public function promotionLimit(',
            'public function reviewQueueLimit(',
            'public function qualityHistoryDays(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Memory/MemoryQueryInput.php: memory query input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$this->input->registryLimit(',
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryRegistryService.php: memory registry must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->verbatimLimit(',
        ] as $token) {
            if (! str_contains($verbatim, $token)) {
                $violations[] = "app/Services/Ai/AtlasVerbatimMemoryService.php: verbatim memory must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->governanceScanLimit(',
            '$this->input->relationLimit(',
        ] as $token) {
            if (! str_contains($governance, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryGovernanceService.php: memory governance must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->governanceScanLimit(',
        ] as $token) {
            if (! str_contains($privacy, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryPrivacyService.php: memory privacy must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->promotionLimit(',
        ] as $token) {
            if (! str_contains($deltaPromotion, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryDeltaPromotionService.php: memory delta promotion must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->promotionLimit(',
        ] as $token) {
            if (! str_contains($learningPromotion, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryLearningPromotionService.php: memory learning promotion must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->promotionLimit(',
        ] as $token) {
            if (! str_contains($maintenance, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryMaintenanceService.php: memory maintenance must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->reviewQueueLimit(',
        ] as $token) {
            if (! str_contains($reviewQueue, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryReviewQueueService.php: memory review queue must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->qualityHistoryDays(',
            '$this->input->registryLimit(',
        ] as $token) {
            if (! str_contains($quality, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryQualityService.php: memory quality must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$input->registryLimit($this->option(\'limit\'))',
        ] as $token) {
            if (! str_contains($listCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryListCommand.php: memory list command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$this->memoryInput()->verbatimLimit($this->option(\'limit\'))',
            'private function memoryInput(): MemoryQueryInput',
        ] as $token) {
            if (! str_contains($verbatimCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryVerbatimCommand.php: verbatim command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$this->memoryInput()->relationLimit($this->option(\'limit\'))',
            'private function memoryInput(): MemoryQueryInput',
        ] as $token) {
            if (! str_contains($relationsCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryRelationsCommand.php: memory relations command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$input->reviewQueueLimit($this->option(\'limit\'))',
        ] as $token) {
            if (! str_contains($reviewQueueCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryReviewQueueCommand.php: memory review queue command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$this->memoryInput()->governanceScanLimit($this->option(\'limit\'))',
            'private function memoryInput(): MemoryQueryInput',
        ] as $token) {
            if (! str_contains($governanceCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryGovernanceCommand.php: memory governance command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$this->memoryInput()->governanceScanLimit($this->option(\'limit\'))',
            'private function memoryInput(): MemoryQueryInput',
        ] as $token) {
            if (! str_contains($privacyCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryPrivacyCommand.php: memory privacy command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_memory_query_limits_with_canonical_caps',
            'MemoryQueryInput::MAX_MEMORY_LIMIT',
            'MemoryQueryInput::MAX_SCAN_LIMIT',
            'MemoryQueryInput::MAX_QUALITY_HISTORY_DAYS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Memory/MemoryQueryInputTest.php: memory query input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'memory query input contract',
            'AP-79',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe memory query input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanConversationContextInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Context/ConversationContextInput.php');
        $builderPath = app_path('Services/Ai/AiConversationContextBuilder.php');
        $testPath = base_path('tests/Unit/Ai/Context/ConversationContextInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'final class ConversationContextInput',
            'public const DEFAULT_RECENT_TURN_LIMIT = 12',
            'public const MIN_RECENT_TURN_LIMIT = 2',
            'public const MAX_RECENT_TURN_LIMIT = 40',
            'public const DEFAULT_PAYLOAD_TURN_LIMIT = 8',
            'public const MIN_PAYLOAD_TURN_LIMIT = 1',
            'public const MAX_PAYLOAD_TURN_LIMIT = 20',
            'public function recentTurnLimit(',
            'public function payloadTurnLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Context/ConversationContextInput.php: conversation context input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly ConversationContextInput $input',
            '$this->input->recentTurnLimit()',
            '$this->input->payloadTurnLimit()',
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/AiConversationContextBuilder.php: conversation context builder must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_conversation_context_turn_limits_with_canonical_caps',
            'ConversationContextInput::MAX_RECENT_TURN_LIMIT',
            'ConversationContextInput::MAX_PAYLOAD_TURN_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/ConversationContextInputTest.php: conversation context input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'conversation context input contract',
            'AP-81',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe conversation context input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRetrievalRankInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Context/RetrievalRankInput.php');
        $searchPath = app_path('Services/Ai/Search/SessionSearchService.php');
        $promptPath = app_path('Services/Ai/AiPromptBuilder.php');
        $runtimePath = app_path('Services/Ai/Runtime/AiToolRuntime.php');
        $testPath = base_path('tests/Unit/Ai/Context/RetrievalRankInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $search = File::exists($searchPath) ? File::get($searchPath) : '';
        $prompt = File::exists($promptPath) ? File::get($promptPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'final class RetrievalRankInput',
            'public const DEFAULT_SESSION_TOP_N = 3',
            'public const MAX_SESSION_TOP_N = 10',
            'public const MAX_PROMPT_SESSION_TOP_N = 5',
            'public function sessionTopN(',
            'public function promptSessionTopN(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Context/RetrievalRankInput.php: retrieval rank input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly RetrievalRankInput $input',
            '$this->input->sessionTopN($topN)',
        ] as $token) {
            if (! str_contains($search, $token)) {
                $violations[] = "app/Services/Ai/Search/SessionSearchService.php: session search must use shared retrieval rank contract [{$token}]";
            }
        }

        foreach ([
            '?RetrievalRankInput $retrievalRankInput = null',
            '->promptSessionTopN(data_get($config, \'top_n\'))',
        ] as $token) {
            if (! str_contains($prompt, $token)) {
                $violations[] = "app/Services/Ai/AiPromptBuilder.php: prompt session search must use shared retrieval rank contract [{$token}]";
            }
        }

        foreach ([
            'private readonly RetrievalRankInput $retrievalRankInput',
            '$this->retrievalRankInput->sessionTopN($invocation->argument(\'top_n\'))',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/Runtime/AiToolRuntime.php: runtime session.search must use shared retrieval rank contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_retrieval_rank_limits_with_canonical_caps',
            'RetrievalRankInput::MAX_SESSION_TOP_N',
            'RetrievalRankInput::MAX_PROMPT_SESSION_TOP_N',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/RetrievalRankInputTest.php: retrieval rank input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'retrieval rank input contract',
            'AP-82',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe retrieval rank input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAtlasVaultCommandInputContract(): array
    {
        $inputPath = app_path('Services/Semantic/AtlasVaultCommandInput.php');
        $commandPath = app_path('Console/Commands/AtlasVaultCommand.php');
        $testPath = base_path('tests/Unit/AtlasVaultCommandInputTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $vaultDocsPath = base_path('docs/engineering-knowledge-base/obsidian-atlas-vault.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = $this->kernelDocumentationCorpus();
        $vaultDocs = File::exists($vaultDocsPath) ? File::get($vaultDocsPath) : '';

        foreach ([
            'final class AtlasVaultCommandInput',
            'public const DEFAULT_SYNC_LIMIT = 200',
            'public const DEFAULT_CONFLICT_LIMIT = 100',
            'public const MAX_COMMAND_LIMIT = 1000',
            'public function syncLimit(',
            'public function conflictLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Semantic/AtlasVaultCommandInput.php: AtlasVault command input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private AtlasVaultCommandInput $vaultInput',
            'AtlasVaultCommandInput $input',
            '$this->vaultInput->syncLimit($this->option(\'limit\'))',
            '$this->vaultInput->conflictLimit($this->option(\'limit\'))',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasVaultCommand.php: AtlasVault command must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_atlas_vault_command_limits_with_canonical_caps',
            'AtlasVaultCommandInput::DEFAULT_SYNC_LIMIT',
            'AtlasVaultCommandInput::MAX_COMMAND_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/AtlasVaultCommandInputTest.php: AtlasVault command input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas vault command input contract',
            'AP-83',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($vaultDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe AtlasVault command input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanMemoryRecallInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Memory/MemoryRecallInput.php');
        $retrievalPath = app_path('Services/Ai/AtlasHybridMemoryRetrievalService.php');
        $composerPath = app_path('Services/Ai/AtlasMemoryContextComposer.php');
        $testPath = base_path('tests/Unit/Ai/Memory/MemoryRecallInputTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $retrieval = File::exists($retrievalPath) ? File::get($retrievalPath) : '';
        $composer = File::exists($composerPath) ? File::get($composerPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = $this->kernelDocumentationCorpus();
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            'final class MemoryRecallInput',
            'public const DEFAULT_RECALL_LIMIT = 10',
            'public const MAX_RECALL_LIMIT = 50',
            'public const MAX_REGISTRY_CANDIDATE_LIMIT = 100',
            'public const MAX_VERBATIM_CANDIDATE_LIMIT = 50',
            'public const MAX_SEMANTIC_CANDIDATE_LIMIT = 50',
            'public const DEFAULT_BUDGET_CHARS = 2400',
            'public const MAX_BUDGET_CHARS = 12000',
            'public const DEFAULT_ITEM_CHARS = 360',
            'public const MAX_ITEM_CHARS = 3000',
            'public const DEFAULT_REGISTRY_EXCERPT_CHARS = 900',
            'public const MAX_REGISTRY_EXCERPT_CHARS = 5000',
            'public function recallLimit(',
            'public function registryCandidateLimit(',
            'public function verbatimCandidateLimit(',
            'public function semanticCandidateLimit(',
            'public function budgetChars(',
            'public function itemChars(',
            'public function registryExcerptChars(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Memory/MemoryRecallInput.php: memory recall input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryRecallInput $input',
            '$this->input->recallLimit($options[\'limit\'] ?? null)',
            '$this->input->registryCandidateLimit($options[\'registry_limit\'] ?? null, $limit)',
            '$this->input->verbatimCandidateLimit($options[\'verbatim_limit\'] ?? null, $limit)',
            '$this->input->semanticCandidateLimit($options[\'semantic_limit\'] ?? null, $limit)',
            '$this->input->budgetChars($options[\'budget_chars\'] ?? null)',
            '$this->input->itemChars($options[\'item_chars\'] ?? null)',
            '$this->input->registryExcerptChars()',
        ] as $token) {
            if (! str_contains($retrieval, $token)) {
                $violations[] = "app/Services/Ai/AtlasHybridMemoryRetrievalService.php: hybrid memory recall must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryRecallInput $input',
            '$this->input->recallLimit($options[\'memory_recall_limit\'] ?? null)',
            '$this->input->budgetChars($options[\'memory_recall_budget_chars\'] ?? null)',
            '$this->input->itemChars($options[\'memory_recall_item_chars\'] ?? null)',
        ] as $token) {
            if (! str_contains($composer, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryContextComposer.php: memory context composer must use shared recall input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_hybrid_memory_recall_limits_with_canonical_caps',
            'MemoryRecallInput::MAX_RECALL_LIMIT',
            'MemoryRecallInput::MAX_REGISTRY_CANDIDATE_LIMIT',
            'MemoryRecallInput::MAX_BUDGET_CHARS',
            'MemoryRecallInput::MAX_REGISTRY_EXCERPT_CHARS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Memory/MemoryRecallInputTest.php: memory recall input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'memory recall input contract',
            'AP-84',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe memory recall input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanContextPackMemoryInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Context/ContextPackMemoryInput.php');
        $builderPath = app_path('Services/Ai/AiContextPackBuilder.php');
        $testPath = base_path('tests/Unit/Ai/Context/ContextPackMemoryInputTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = $this->kernelDocumentationCorpus();
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            'final class ContextPackMemoryInput',
            'public const DEFAULT_MEMORY_REGISTRY_LIMIT = 8',
            'public const MAX_MEMORY_REGISTRY_LIMIT = 50',
            'public const DEFAULT_VERBATIM_RECALL_LIMIT = 4',
            'public const MAX_VERBATIM_RECALL_LIMIT = 25',
            'public const DEFAULT_VERBATIM_RECALL_BUDGET_CHARS = 1600',
            'public const MAX_VERBATIM_RECALL_BUDGET_CHARS = 8000',
            'public const DEFAULT_VERBATIM_RECALL_ITEM_CHARS = 600',
            'public const MAX_VERBATIM_RECALL_ITEM_CHARS = 3000',
            'public const DEFAULT_MEMORY_REGISTRY_EXCERPT_CHARS = 900',
            'public const MAX_MEMORY_REGISTRY_EXCERPT_CHARS = 5000',
            'public function memoryRegistryLimit(',
            'public function verbatimRecallLimit(',
            'public function verbatimRecallBudgetChars(',
            'public function verbatimRecallItemChars(',
            'public function memoryRegistryExcerptChars(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Context/ContextPackMemoryInput.php: context pack memory input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private ContextPackMemoryInput $memoryInput',
            '$this->memoryInput->memoryRegistryLimit($options[\'memory_registry_limit\'] ?? null)',
            '$this->memoryInput->verbatimRecallLimit($options[\'verbatim_recall_limit\'] ?? null)',
            '$this->memoryInput->memoryRegistryExcerptChars()',
            '$this->memoryInput->verbatimRecallBudgetChars($options[\'verbatim_recall_budget_chars\'] ?? null)',
            '$this->memoryInput->verbatimRecallItemChars($options[\'verbatim_recall_item_chars\'] ?? null)',
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/AiContextPackBuilder.php: context pack memory paths must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_context_pack_memory_limits_with_canonical_caps',
            'ContextPackMemoryInput::MAX_MEMORY_REGISTRY_LIMIT',
            'ContextPackMemoryInput::MAX_VERBATIM_RECALL_LIMIT',
            'ContextPackMemoryInput::MAX_VERBATIM_RECALL_BUDGET_CHARS',
            'ContextPackMemoryInput::MAX_MEMORY_REGISTRY_EXCERPT_CHARS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/ContextPackMemoryInputTest.php: context pack memory input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'context pack memory input contract',
            'AP-85',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe context pack memory input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSemanticContextInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Context/SemanticContextInput.php');
        $builderPath = app_path('Services/Ai/AiContextPackBuilder.php');
        $testPath = base_path('tests/Unit/Ai/Context/SemanticContextInputTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = $this->kernelDocumentationCorpus();
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            'final class SemanticContextInput',
            'public const DEFAULT_CONTEXT_NOTE_LIMIT = 5',
            'public const MAX_CONTEXT_NOTE_LIMIT = 30',
            'public const DEFAULT_CONTEXT_EXCERPT_CHARS = 1200',
            'public const MAX_CONTEXT_EXCERPT_CHARS = 8000',
            'public function contextNoteLimit(',
            'public function contextExcerptChars(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Context/SemanticContextInput.php: semantic context input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private SemanticContextInput $semanticInput',
            '$this->semanticInput->contextNoteLimit($options[\'context_note_limit\'] ?? null)',
            '$this->semanticInput->contextExcerptChars()',
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/AiContextPackBuilder.php: semantic context paths must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_semantic_context_limits_with_canonical_caps',
            'SemanticContextInput::MAX_CONTEXT_NOTE_LIMIT',
            'SemanticContextInput::MAX_CONTEXT_EXCERPT_CHARS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/SemanticContextInputTest.php: semantic context input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'semantic context input contract',
            'AP-86',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe semantic context input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanTestCommandInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Runtime/TestCommandInput.php');
        $resolverPath = app_path('Services/Ai/Runtime/AtlasTestCommandResolver.php');
        $testPath = base_path('tests/Unit/Ai/Runtime/TestCommandInputTest.php');
        $resolverTestPath = base_path('tests/Unit/AtlasTestCommandResolverTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $resolver = File::exists($resolverPath) ? File::get($resolverPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $resolverTest = File::exists($resolverTestPath) ? File::get($resolverTestPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'final class TestCommandInput',
            "public const DEFAULT_MEMORY_LIMIT = '1024M'",
            'public function memoryLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Runtime/TestCommandInput.php: test command input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly TestCommandInput $input',
            '$this->input->memoryLimit()',
        ] as $token) {
            if (! str_contains($resolver, $token)) {
                $violations[] = "app/Services/Ai/Runtime/AtlasTestCommandResolver.php: test command resolver must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_test_command_memory_limit_with_canonical_default',
            'TestCommandInput::DEFAULT_MEMORY_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Runtime/TestCommandInputTest.php: test command input contract must be covered [{$token}]";
            }
        }

        if (! str_contains($resolverTest, 'memory_limit=1024M')) {
            $violations[] = 'tests/Unit/AtlasTestCommandResolverTest.php: resolver must preserve canonical test memory default in generated command';
        }

        foreach ([
            'test command input contract',
            'AP-88',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe test command input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSchedulerInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Scheduling/AtlasSchedulerInput.php');
        $servicePath = app_path('Services/Ai/Scheduling/AtlasCliSchedulerService.php');
        $commandPath = app_path('Console/Commands/AtlasSchedulerTickCommand.php');
        $testPath = base_path('tests/Unit/Ai/Scheduling/AtlasSchedulerInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();

        foreach ([
            'final class AtlasSchedulerInput',
            'public const DEFAULT_DUE_TASK_LIMIT = 25',
            'public const MAX_DUE_TASK_LIMIT = 100',
            'public function dueTaskLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Scheduling/AtlasSchedulerInput.php: scheduler input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly AtlasSchedulerInput $input',
            '$this->input->dueTaskLimit($limit)',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Scheduling/AtlasCliSchedulerService.php: scheduler service must use shared scheduler input [{$token}]";
            }
        }

        foreach ([
            'AtlasSchedulerInput $input',
            '$limit = $input->dueTaskLimit($this->option(\'limit\'))',
            'previewDueTasks(limit: $limit)',
            'limit: $limit',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasSchedulerTickCommand.php: scheduler tick command must use shared scheduler input [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_scheduler_due_task_limit',
            'AtlasSchedulerInput::DEFAULT_DUE_TASK_LIMIT',
            'AtlasSchedulerInput::MAX_DUE_TASK_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Scheduling/AtlasSchedulerInputTest.php: scheduler input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'scheduler input contract',
            'AP-97',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe scheduler input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanGatewayDecisionReceiptPropagation(): array
    {
        $path = app_path('Services/Ai/AiGatewayService.php');
        if (! File::exists($path)) {
            return ["missing gateway [{$path}]"];
        }

        $contents = File::get($path);
        $checks = [
            'gateway emits a trace-level receipt' => 'decisionReceiptForTrace(',
            'trace metadata persists the receipt' => "'decision_receipt' => \$decisionReceipt",
            'scout job accepts the receipt as an explicit parameter' => 'array $decisionReceipt',
            'scout job is called with the same receipt' => 'decisionReceipt: $decisionReceipt',
        ];

        $violations = [];
        foreach ($checks as $label => $token) {
            if (! str_contains($contents, $token)) {
                $violations[] = "app/Services/Ai/AiGatewayService.php: missing {$label} [{$token}]";
            }
        }

        $receiptWrites = substr_count($contents, "'decision_receipt' => \$decisionReceipt");
        if ($receiptWrites < 5) {
            $violations[] = "app/Services/Ai/AiGatewayService.php: expected receipt propagation to trace, primary job, council jobs and scout jobs; found {$receiptWrites} writes";
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanWorkerDecisionReceiptRuntimeGuard(): array
    {
        $path = app_path('Services/Ai/AiWorker.php');
        if (! File::exists($path)) {
            return ["missing worker [{$path}]"];
        }

        $contents = File::get($path);
        $guardPath = app_path('Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php');
        $guardContents = File::exists($guardPath) ? File::get($guardPath) : '';
        $checks = [
            'worker delegates receipt validation to the kernel guard' => 'DecisionReceiptRuntimeGuard',
            'worker evaluates receipt before provider lookup' => 'violationForJob($job, $providerKey, $job->model)',
            'worker persists receipt metadata through the kernel guard' => 'receiptForJob($job)',
            'kernel guard class exists' => 'class DecisionReceiptRuntimeGuard',
            'worker blocks expired receipts' => 'decision_receipt_expired',
            'worker blocks dry-run receipts' => 'decision_receipt_dry_run',
            'worker blocks invalid receipts' => 'decision_receipt_invalid',
            'worker blocks provider mismatches' => 'decision_receipt_provider_mismatch',
            'worker blocks model mismatches' => 'decision_receipt_model_mismatch',
            'kernel guard validates runtime provider' => 'providerSelectionViolation(',
            'worker parses expires_at as immutable time' => 'CarbonImmutable::parse($expiresAt)',
            'worker marks receipt blocks as no provider call' => "'decision_receipt_expired'",
        ];

        $violations = [];
        foreach ($checks as $label => $token) {
            if (! str_contains($contents, $token) && ! str_contains($guardContents, $token)) {
                $violations[] = "app/Services/Ai/AiWorker.php: missing {$label} [{$token}]";
            }
        }

        if ($guardContents === '') {
            $violations[] = 'app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php: missing dedicated runtime guard';
        }

        $guardPosition = strpos($contents, 'violationForJob($job, $providerKey, $job->model)');
        $providerLookupPosition = strpos($contents, '$provider = $this->providers->get($providerKey);');
        if ($guardPosition === false || $providerLookupPosition === false || $guardPosition > $providerLookupPosition) {
            $violations[] = 'app/Services/Ai/AiWorker.php: decision receipt runtime guard must run before provider lookup/execution';
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanContinueResumeContractFactory(): array
    {
        $factoryPath = app_path('Services/Ai/Programming/ProgrammingSurfaceContractFactory.php');
        $testPath = base_path('tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php');
        $continuePath = app_path('Console/Commands/AtlasCliContinueCommand.php');

        $factory = File::exists($factoryPath) ? File::get($factoryPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $continue = File::exists($continuePath) ? File::get($continuePath) : '';

        $violations = [];

        foreach ([
            'public function resume(array $resume, array $operatorOptions, array $command, array $openBrain): array',
            "'schema_version' => 'atlas.cli_continue.resume_contract.v1'",
            "'surface' => 'atlas_cli_continue'",
            "'canonical_surface' => 'atlas_cli_dev'",
            "'target_command' => 'atlas:cli:dev'",
            "'target_surface' => 'atlas_cli_dev'",
            "'builder' => AtlasProgrammingSurfaceCommandBuilder::class",
            "'plan_id' => (string) (\$resume['plan_id'] ?? '')",
            "'programming_intent' => \$operatorOptions['programming_intent'] ?? (\$operatorOptions['intent'] ?? null)",
            "'open_brain' => \$openBrain",
            "'resume' => in_array('--resume='.(string) (\$resume['plan_id'] ?? ''), \$command, true)",
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php: Programming must own the shared resume_contract shape [{$token}]";
            }
        }

        foreach ([
            'test_resume_contract_preserves_continue_origin_and_canonical_dev_target',
            "'atlas.cli_continue.resume_contract.v1'",
            "'atlas_cli_continue'",
            "'atlas_cli_dev'",
            "'target_surface'",
            "'dev_flags'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php: resume contract factory needs explicit coverage [{$token}]";
            }
        }

        foreach ([
            "'schema_version' => 'atlas.cli_continue.resume_contract.v1'",
            "'canonical_surface' => 'atlas_cli_dev'",
            "'target_surface' => 'atlas_cli_dev'",
            "'builder' => AtlasProgrammingSurfaceCommandBuilder::class",
        ] as $token) {
            if (str_contains($continue, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliContinueCommand.php: continue command must not inline resume_contract schema; use ProgrammingSurfaceContractFactory [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanFixContractFactory(): array
    {
        $factoryPath = app_path('Services/Ai/Programming/ProgrammingSurfaceContractFactory.php');
        $builderPath = app_path('Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php');
        $devPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $testPath = base_path('tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php');
        $fixTestPath = base_path('tests/Feature/AtlasCliFixCommandTest.php');

        $factory = File::exists($factoryPath) ? File::get($factoryPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $dev = File::exists($devPath) ? File::get($devPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $fixTest = File::exists($fixTestPath) ? File::get($fixTestPath) : '';

        $violations = [];

        foreach ([
            'public function fix(array $operatorOptions, array $command): array',
            "'schema_version' => 'atlas.cli_fix.contract.v1'",
            "'surface' => 'atlas_cli_fix'",
            "'canonical_surface' => 'atlas_cli_dev'",
            "'target_command' => 'atlas:cli:dev'",
            "'target_surface' => 'atlas_cli_dev'",
            "'flow' => 'programming.repair'",
            "'runtime' => 'dev_repair_executor'",
            "'orchestrator' => 'AtlasProgrammingOrchestrator'",
            "'repair_required' => true",
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php: Programming must own the shared fix_contract shape [{$token}]";
            }
        }

        foreach ([
            "'--surface-origin' => 'atlas_cli_fix'",
            "'--repair' => true",
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php: atlas fix must preserve its origin when translated into atlas:cli:dev [{$token}]";
            }
        }

        foreach ([
            '{--surface-origin= : Internal surface alias origin for thin wrapper commands}',
            "\$this->surfaceOrigin() === 'atlas_cli_fix'",
            "\$devPlan['fix_contract'] = app(ProgrammingSurfaceContractFactory::class)->fix(",
            'private function surfaceOrigin(): ?string',
            'private function fixContractCommand(): array',
        ] as $token) {
            if (! str_contains($dev, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliDevCommand.php: atlas dev must attach canonical fix_contract for atlas fix aliases [{$token}]";
            }
        }

        foreach ([
            'test_fix_contract_preserves_fix_origin_and_canonical_dev_repair_target',
            "'atlas.cli_fix.contract.v1'",
            "'atlas_cli_fix'",
            "'programming.repair'",
            "'repair_required'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php: fix contract factory needs explicit coverage [{$token}]";
            }
        }

        foreach ([
            "'dev_execution_plan.fix_contract.schema_version'",
            "'dev_execution_plan.fix_contract.surface'",
            "'dev_execution_plan.fix_contract.canonical_surface'",
            "'dev_execution_plan.fix_contract.flow'",
            "'dev_execution_plan.fix_contract.dev_flags.repair'",
        ] as $token) {
            if (! str_contains($fixTest, $token)) {
                $violations[] = "tests/Feature/AtlasCliFixCommandTest.php: atlas fix needs coverage proving fix_contract survives the dev alias [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanChatDevProgrammingContract(): array
    {
        $chatPath = app_path('Console/Commands/AiChatCommand.php');
        $testPath = base_path('tests/Feature/Console/AiChatProviderChoiceTest.php');

        $chat = File::exists($chatPath) ? File::get($chatPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            'ProgrammingSurfaceContractFactory',
            "\$payload['programming_chat_contract'] = app(ProgrammingSurfaceContractFactory::class)->chatDev(\$devPlan, \$programmingMessagePlan, \$payload['programming_dispatch'])",
        ] as $token) {
            if (! str_contains($chat, $token)) {
                $violations[] = "app/Console/Commands/AiChatCommand.php: atlas chat --dev must emit programming_chat_contract tied to Kernel Pipeline and AtlasProgrammingOrchestrator [{$token}]";
            }
        }

        foreach ([
            'test_chat_dev_without_explicit_dev_plan_generates_programming_contract',
            'test_chat_dev_attaches_kernel_pipeline_to_legacy_declared_dev_plan',
            "'atlas.ai_chat.programming_contract.v1'",
            "'programming_chat_contract.programming_flow'",
            "'programming_chat_contract.kernel_pipeline_flow'",
            "'programming_chat_contract.kernel_pipeline_provider_execution_allowed'",
            "'programming_chat_contract.kernel_pipeline_contract_required'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Console/AiChatProviderChoiceTest.php: chat dev needs coverage proving programming_chat_contract for auto and declared dev plans [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanChatProgrammingContractFactory(): array
    {
        $factoryPath = app_path('Services/Ai/Programming/ProgrammingSurfaceContractFactory.php');
        $testPath = base_path('tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php');
        $chatPath = app_path('Console/Commands/AiChatCommand.php');

        $factory = File::exists($factoryPath) ? File::get($factoryPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $chat = File::exists($chatPath) ? File::get($chatPath) : '';

        $violations = [];

        foreach ([
            'public function chatDev(?array $devPlan, array $programmingMessagePlan, ?array $dispatch): array',
            "'schema_version' => 'atlas.ai_chat.programming_contract.v1'",
            "'surface' => 'atlas_ai_chat'",
            "'mode' => 'dev'",
            "'orchestrator' => 'AtlasProgrammingOrchestrator'",
            "'programming_flow' => data_get(\$programmingMessagePlan, 'policy_profile.profile_id')",
            "'dispatch_path' => data_get(\$dispatch, 'dispatch_path')",
            "'kernel_pipeline_surface' => data_get(\$devPlan, 'kernel_pipeline.input.surface_id')",
            "'kernel_pipeline_flow' => data_get(\$devPlan, 'kernel_pipeline.input.safe_hints.flow')",
            "'kernel_pipeline_provider_execution_allowed' => (bool) data_get(\$devPlan, 'kernel_pipeline.provider_execution_allowed', false)",
            "'kernel_pipeline_contract_required' => (bool) data_get(\$devPlan, 'kernel_pipeline_contract.required', false)",
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php: Programming must own the shared chat dev contract shape [{$token}]";
            }
        }

        foreach ([
            'test_chat_dev_contract_preserves_programming_dispatch_and_kernel_pipeline_binding',
            "'atlas.ai_chat.programming_contract.v1'",
            "'atlas_ai_chat'",
            "'programming.repair'",
            "'chat_dev_auto_plan'",
            "'kernel_pipeline_contract_required'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php: chat dev contract factory needs explicit coverage [{$token}]";
            }
        }

        foreach ([
            "'schema_version' => 'atlas.ai_chat.programming_contract.v1'",
            "'kernel_pipeline_surface' => data_get(\$devPlan, 'kernel_pipeline.input.surface_id')",
            "'kernel_pipeline_contract_required' => (bool) data_get(\$devPlan, 'kernel_pipeline_contract.required', false)",
        ] as $token) {
            if (str_contains($chat, $token)) {
                $violations[] = "app/Console/Commands/AiChatCommand.php: chat command must not inline programming_chat_contract schema; use ProgrammingSurfaceContractFactory [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSurfaceAliasCanonicalization(): array
    {
        $registryPath = app_path('Services/Ai/Surface/SurfaceAdapterRegistry.php');
        $testPath = base_path('tests/Unit/Ai/Surface/SurfaceAdaptersTest.php');
        $validateTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');

        $registry = File::exists($registryPath) ? File::get($registryPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $validateTest = File::exists($validateTestPath) ? File::get($validateTestPath) : '';

        $violations = [];

        foreach ([
            "'atlas_dev' => 'atlas_cli_dev'",
            "'atlas_forge' => 'atlas_cli_forge'",
            "'atlas_fix' => 'atlas_cli_dev'",
            "'atlas_continue' => 'atlas_cli_dev'",
            "'atlas_ask' => 'atlas_cli_chat'",
            "'atlas_chat' => 'atlas_cli_chat'",
            "'atlas_cli_fix' => 'atlas_cli_dev'",
            "'atlas_cli_continue' => 'atlas_cli_dev'",
            "'atlas_cli_ask' => 'atlas_cli_chat'",
            "'aliases' => self::SURFACE_ALIASES",
            'surface alias [{$alias}] points to unsupported surface',
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/Surface/SurfaceAdapterRegistry.php: human CLI aliases must canonicalize to existing surface adapters [{$token}]";
            }
        }

        foreach ([
            "\$this->assertSame('atlas_cli_chat', \$registry->canonicalSurfaceId('atlas_ask'))",
            "\$this->assertSame('atlas_cli_dev', \$registry->canonicalSurfaceId('atlas_fix'))",
            "\$this->assertSame('atlas_cli_dev', \$registry->canonicalSurfaceId('atlas_continue'))",
            "\$this->assertSame('atlas_cli_forge', \$registry->canonicalSurfaceId('atlas_forge'))",
            "\$this->assertSame('atlas_cli_chat', \$registry->get('atlas_ask')->surfaceId())",
            "\$this->assertSame('atlas_cli_dev', \$registry->get('atlas_cli_continue')->surfaceId())",
            "\$this->assertSame('atlas_cli_chat', \$report['aliases']['atlas_ask'])",
            "\$this->assertSame('atlas_cli_dev', \$report['aliases']['atlas_cli_continue'])",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Surface/SurfaceAdaptersTest.php: surface alias canonicalization must be covered by registry tests [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel.surface_adapters.aliases.atlas_ask')",
            "data_get(\$payload, 'kernel.surface_adapters.aliases.atlas_cli_continue')",
            "'kernel.static_scan.ap24_surface_alias_canonicalization.valid'",
            "'kernel.static_scan.ap24_surface_alias_canonicalization.violations'",
        ] as $token) {
            if (! str_contains($validateTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: architecture validate must publish AP24 and surface aliases [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecideModelSelectionContract(): array
    {
        $decidePath = app_path('Services/Ai/AtlasDecideService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasDecideReceiptIntegrationTest.php');
        $cliTestPath = base_path('tests/Feature/AiAtlasDecideContractTest.php');

        $decide = File::exists($decidePath) ? File::get($decidePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $cliTest = File::exists($cliTestPath) ? File::get($cliTestPath) : '';

        $violations = [];

        foreach ([
            "\$selectionMode = \$manualProvider !== null ? 'manual_override' : \$this->automaticModelSelectionMode(\$policy)",
            "'selection_mode' => \$selectionMode",
            "'model_selection_authority' => 'atlas_decide'",
            "'available_selection_modes' => ['auto_best_allowed', 'auto_best_available', 'manual_override']",
            "'selection_mode' => \$providerSelection['selection_mode'] ?? data_get(\$decision, 'receipt_v2.provider_selection.selection_mode')",
            "'model_selection_authority' => \$providerSelection['model_selection_authority'] ?? 'atlas_decide'",
            "return (\$policy['default_model_policy'] ?? null) === 'best_quality'",
            "? 'auto_best_available'",
            ": 'auto_best_allowed'",
        ] as $token) {
            if (! str_contains($decide, $token)) {
                $violations[] = "app/Services/Ai/AtlasDecideService.php: Atlas Decide must expose explicit model selection modes and remain the selection authority [{$token}]";
            }
        }

        foreach ([
            "'provider_selection.selection_mode'",
            "'provider_selection.model_selection_authority'",
            "'provider_selection.available_selection_modes'",
            "'auto_best_allowed'",
            "'auto_best_available'",
            "'manual_override'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasDecideReceiptIntegrationTest.php: Decide receipt integration must prove public model selection mode contract [{$token}]";
            }
        }

        foreach ([
            "'decision.selection_mode'",
            "'decision.model_selection_authority'",
            "'decision.available_selection_modes'",
        ] as $token) {
            if (! str_contains($cliTest, $token)) {
                $violations[] = "tests/Feature/AiAtlasDecideContractTest.php: atlas:ai:decide JSON must expose public model selection mode contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanChatModelSelectionContract(): array
    {
        $chatPath = app_path('Console/Commands/AiChatCommand.php');
        $testPath = base_path('tests/Feature/Console/AiChatProviderChoiceTest.php');

        $chat = File::exists($chatPath) ? File::get($chatPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            'ModelSelectionContractFactory',
            "'model_selection_contract' => \$this->modelSelectionContract(",
            'private function modelSelectionContract(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array',
            '->forAiChat($provider, $modelSelection, $modelOverride, $fairMode, $context)',
            "'model_selection_contract' => data_get(\$trace->metadata, 'model_selection_contract')",
        ] as $token) {
            if (! str_contains($chat, $token)) {
                $violations[] = "app/Console/Commands/AiChatCommand.php: atlas chat must expose model_selection_contract with Atlas Decide authority [{$token}]";
            }
        }

        foreach ([
            "'model_selection_contract.schema_version'",
            "'model_selection_contract.authority'",
            "'model_selection_contract.selection_mode'",
            "'model_selection_contract.available_selection_modes'",
            "'model_selection_contract.operator_requested_provider'",
            "'model_selection_contract.requested_model'",
            "'model_selection_contract.requested_model_alias'",
            "data_get(\$payload, 'model_selection_contract')",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Console/AiChatProviderChoiceTest.php: atlas chat needs tests for model selection contract in auto and manual override paths [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDocumentationHealthCuratorReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-145-documentation-health-curator-review.md');
        $docOsPath = base_path('docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $docOs = File::exists($docOsPath) ? File::get($docOsPath) : '';
        $violations = [];

        foreach ([
            'private function documentationHealthFindings(array $filters = []): array',
            'atlas.self_improvement.documentation_health_gap.v1',
            'split_oversized_active_docs',
            'documentation.oversized_docs',
            'split_required_grandfathered',
            'self-improvement:documentation-health:',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-145 Documentation Health must become Curator findings [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_oversized_active_documentation_from_architecture_validation',
            'atlas.self_improvement.documentation_health_gap.v1',
            'split_oversized_active_docs',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-145 Documentation Health Curator review must be tested [{$token}]";
            }
        }

        foreach ([
            'AP-145',
            'Documentation Health Curator Review',
            'atlas.self_improvement.documentation_health_gap.v1',
            'split_oversized_active_docs',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-145 Documentation Health Curator review must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-145-documentation-health-curator-review.md: AP-145 contract doc must exist [{$token}]";
            }
        }

        foreach ([
            'Documentation Health Curator Review',
            'atlas.self_improvement.documentation_health_gap.v1',
            'split_oversized_active_docs',
        ] as $token) {
            if (! str_contains($docOs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md: AP-145 must be named in Documentation OS [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanToolTierHotPathPolicy(): array
    {
        $gatewayPath = app_path('Services/Ai/AiGatewayService.php');
        $policyPath = app_path('Services/Tools/AtlasToolPolicyEngine.php');
        $violations = [];

        if (! File::exists($gatewayPath)) {
            $violations[] = "missing gateway [{$gatewayPath}]";
        }

        if (! File::exists($policyPath)) {
            $violations[] = "missing tool policy engine [{$policyPath}]";
        }

        $gateway = File::exists($gatewayPath) ? File::get($gatewayPath) : '';
        $policy = File::exists($policyPath) ? File::get($policyPath) : '';

        $gatewayChecks = [
            'gateway records requested tool execution tier' => 'requested_execution_tier',
            'gateway records contract max execution tier' => 'max_execution_tier',
            'gateway records hot path flag' => 'hot_path',
            'gateway blocks T2/T3 hot path requests' => 'execution_tier_hot_path_blocked',
            'gateway blocks tiers above contract' => 'execution_tier_above_contract',
            'gateway compares tier weights' => 'executionTierWeight(',
            'gateway records policy contract blocks to ledger' => "recordPolicyContractBlocked('programming.tools'",
        ];

        foreach ($gatewayChecks as $label => $token) {
            if (! str_contains($gateway, $token)) {
                $violations[] = "app/Services/Ai/AiGatewayService.php: missing {$label} [{$token}]";
            }
        }

        $policyChecks = [
            'tool policy reads max execution tier' => 'max_execution_tier',
            'tool policy blocks tier above budget' => 'execution_tier_above_policy_budget',
            'tool policy compares execution tier weight' => 'tierWeight($executionTier) > $this->tierWeight($maxExecutionTier)',
        ];

        foreach ($policyChecks as $label => $token) {
            if (! str_contains($policy, $token)) {
                $violations[] = "app/Services/Tools/AtlasToolPolicyEngine.php: missing {$label} [{$token}]";
            }
        }

        return $violations;
    }


    private function kernelDocumentationCorpus(): string
    {
        return $this->primitives->kernelDocumentationCorpus();
    }

    private function selfImprovementDomainDocumentationCorpus(): string
    {
        return $this->primitives->selfImprovementDomainDocumentationCorpus();
    }

    /**
     * @param  array<int,string>  $paths
     */
    private function documentationCorpus(array $paths): string
    {
        return $this->primitives->documentationCorpus($paths);
    }
}
