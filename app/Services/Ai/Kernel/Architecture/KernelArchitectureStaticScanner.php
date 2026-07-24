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
use App\Services\Ai\Kernel\Architecture\Scanner\FailureGovernanceAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\GraphRagAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\InboxActionAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\InputContractAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\KernelAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\LedgerAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\McpAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\MemoryLearningAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\MiscGuardAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\ModelSelectionAudit;
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
use App\Services\Ai\Kernel\Architecture\Scanner\SessionAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\SloAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\SurfaceGuardAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\TelemetryRuntimeAudit;
use App\Services\Ai\Kernel\Architecture\Scanner\VoiceAudit;

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
        private SessionAudit $sessionAudit,
        private ModelSelectionAudit $modelSelectionAudit,
        private SurfaceGuardAudit $surfaceGuardAudit,
        private GraphRagAudit $graphRagAudit,
        private FailureGovernanceAudit $failureGovernanceAudit,
        private InputContractAudit $inputContractAudit,
        private MiscGuardAudit $miscGuardAudit,
    ) {}

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
        ] + $this->selfImprovementAudit->checks() + $this->agentBehaviorAudit->checks() + $this->architectureOperationsAudit->checks() + $this->decisionReceiptAudit->checks() + $this->inboxActionAudit->checks() + $this->scheduleReplayAudit->checks() + $this->repairLoopAudit->checks() + $this->engineeringAudit->checks() + $this->cliAudit->checks() + $this->providerAudit->checks() + $this->ledgerAudit->checks() + $this->architectureAudit->checks() + $this->kernelAudit->checks() + $this->voiceAudit->checks() + $this->sloAudit->checks() + $this->mcpAudit->checks() + $this->programmingAudit->checks() + $this->retrievalAudit->checks() + $this->proposalAudit->checks() + $this->openBrainAudit->checks() + $this->contextAudit->checks() + $this->chatAudit->checks() + $this->replayObservabilityAudit->checks() + $this->telemetryRuntimeAudit->checks() + $this->memoryLearningAudit->checks() + $this->sessionAudit->checks() + $this->modelSelectionAudit->checks() + $this->surfaceGuardAudit->checks() + $this->graphRagAudit->checks() + $this->failureGovernanceAudit->checks() + $this->inputContractAudit->checks() + $this->miscGuardAudit->checks();
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
}
