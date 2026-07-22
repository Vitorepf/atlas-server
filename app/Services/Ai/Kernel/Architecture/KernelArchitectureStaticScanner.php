<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\Scanner\AgentBehaviorAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ArchitectureAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ArchitectureOperationsAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ChatAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\CliAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ContextAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\DecisionReceiptAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\EngineeringAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\InboxActionAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\KernelAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\LedgerAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\McpAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\MemoryLearningAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\OpenBrainAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ProgrammingAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ProposalAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ProviderAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\RepairLoopAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ReplayObservabilityAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\RetrievalAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ScanPrimitivesSupport;
use App\Services\Ai\Kernel\Architecture\Scanner\ScheduleReplayAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\SelfImprovementAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\SloAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\TelemetryRuntimeAudit;
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
        private RetrievalAudit $retrievalAudit,
        private ProposalAudit $proposalAudit,
        private OpenBrainAudit $openBrainAudit,
        private ContextAudit $contextAudit,
        private ChatAudit $chatAudit,
        private ReplayObservabilityAudit $replayObservabilityAudit,
        private TelemetryRuntimeAudit $telemetryRuntimeAudit,
        private MemoryLearningAudit $memoryLearningAudit,
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
            'ap24_surface_alias_canonicalization' => fn (): array => $this->scanSurfaceAliasCanonicalization(),
            'ap25_decide_model_selection_contract' => fn (): array => $this->scanDecideModelSelectionContract(),
            'ap31_continue_resume_contract_factory' => fn (): array => $this->scanContinueResumeContractFactory(),
            'ap32_fix_contract_factory' => fn (): array => $this->scanFixContractFactory(),
            'ap81_conversation_context_input_contract' => fn (): array => $this->scanConversationContextInputContract(),
            'ap83_atlas_vault_command_input_contract' => fn (): array => $this->scanAtlasVaultCommandInputContract(),
            'ap86_semantic_context_input_contract' => fn (): array => $this->scanSemanticContextInputContract(),
            'ap88_test_command_input_contract' => fn (): array => $this->scanTestCommandInputContract(),
            'ap97_scheduler_input_contract' => fn (): array => $this->scanSchedulerInputContract(),
            'ap109_operation_completed_inbox_refs_contract' => fn (): array => $this->scanOperationCompletedInboxRefsContract(),
            'ap173_session_bootstrap_docs_split_plan_contract' => fn (): array => $this->scanSessionBootstrapDocsSplitPlanContract(),
            'ap174_session_bootstrap_architecture_operations_contract' => fn (): array => $this->scanSessionBootstrapArchitectureOperationsContract(),
            'ap175_feature_placement_architecture_operations_contract' => fn (): array => $this->scanFeaturePlacementArchitectureOperationsContract(),
            'ap683_local_rag_graph_promotion_review' => fn (): array => $this->scanLocalRagGraphPromotionReview(),
            'ap684_external_graph_harness_contract' => fn (): array => $this->scanExternalGraphHarnessContract(),
            'ap685_constelacao_lens1_usage_review_contract' => fn (): array => $this->scanConstelacaoLens1UsageReviewContract(),
            'ap168_productive_failure_governance_contract' => fn (): array => $this->scanProductiveFailureGovernanceContract(),
            'ap169_personal_worked_example_privacy_contract' => fn (): array => $this->scanPersonalWorkedExamplePrivacyContract(),
            'ap170_predictive_failure_governance_contract' => fn (): array => $this->scanPredictiveFailureGovernanceContract(),
        ] + $this->selfImprovementAudit->checks() + $this->agentBehaviorAudit->checks() + $this->architectureOperationsAudit->checks() + $this->decisionReceiptAudit->checks() + $this->inboxActionAudit->checks() + $this->scheduleReplayAudit->checks() + $this->repairLoopAudit->checks() + $this->engineeringAudit->checks() + $this->cliAudit->checks() + $this->providerAudit->checks() + $this->ledgerAudit->checks() + $this->architectureAudit->checks() + $this->kernelAudit->checks() + $this->voiceAudit->checks() + $this->sloAudit->checks() + $this->mcpAudit->checks() + $this->programmingAudit->checks() + $this->retrievalAudit->checks() + $this->proposalAudit->checks() + $this->openBrainAudit->checks() + $this->contextAudit->checks() + $this->chatAudit->checks() + $this->replayObservabilityAudit->checks() + $this->telemetryRuntimeAudit->checks() + $this->memoryLearningAudit->checks();
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
