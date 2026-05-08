<?php

namespace App\Services\Ai\Kernel\Architecture;

use Illuminate\Support\Facades\File;

class KernelArchitectureStaticScanner
{
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
     *   ap144_rivals_review_inbox_action_contract:array{valid:bool,violations:array<int,string>},
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
     *   ap200_ap_agent_workflow_contracts:array{valid:bool,violations:array<int,string>}
     * }
     */
    public function complianceReport(): array
    {
        $surfaceProviderBypass = $this->scanPhpFilesForForbiddenTokens(app_path('Services/Ai/Surface'), [
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
        ]);

        $surfaceContextBypass = $this->scanPhpFilesForForbiddenTokens(app_path('Services/Ai/Surface'), [
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
        ]);

        $providerDriverBypass = $this->scanPhpFilesForForbiddenTokens(
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
        );
        $decisionReceiptPropagation = $this->scanGatewayDecisionReceiptPropagation();
        $decisionReceiptRuntimeGuard = $this->scanWorkerDecisionReceiptRuntimeGuard();
        $decisionReceiptHashRuntimeGuard = $this->scanDecisionReceiptHashRuntimeGuard();
        $decisionReceiptDeterminismTest = $this->scanDecisionReceiptDeterminismTest();
        $decisionReceiptChainReplay = $this->scanDecisionReceiptChainReplay();
        $decisionReceiptReplaySurfaces = $this->scanDecisionReceiptReplaySurfaces();
        $decisionReceiptReplayCuratorReview = $this->scanDecisionReceiptReplayCuratorReview();
        $decisionReceiptReplayInboxEmission = $this->scanDecisionReceiptReplayInboxEmission();
        $ledgerReplayCommandSurface = $this->scanLedgerReplayCommandSurface();
        $ledgerProjectionRegistryContract = $this->scanLedgerProjectionRegistryContract();
        $ledgerProjectionInboxAction = $this->scanLedgerProjectionInboxAction();
        $ledgerProjectionCuratorActionEmission = $this->scanLedgerProjectionCuratorActionEmission();
        $rivalsReviewInboxActionContract = $this->scanRivalsReviewInboxActionContract();
        $documentationHealthCuratorReview = $this->scanDocumentationHealthCuratorReview();
        $providerCostRateInboxReplay = $this->scanProviderCostRateInboxReplay();
        $agentBehaviorIdentityFragment = $this->scanAgentBehaviorIdentityFragment();
        $agentBehaviorExecutionPlan = $this->scanAgentBehaviorExecutionPlan();
        $agentBehaviorQualityGate = $this->scanAgentBehaviorQualityGate();
        $agentBehaviorReviewActionSurface = $this->scanAgentBehaviorReviewActionSurface();
        $programmingPlanAgentBehaviorContract = $this->scanProgrammingPlanAgentBehaviorContract();
        $programmingHarnessAgentBehaviorContract = $this->scanProgrammingHarnessAgentBehaviorContract();
        $agentBehaviorEvidenceLedger = $this->scanAgentBehaviorEvidenceLedger();
        $agentBehaviorReplayReadModel = $this->scanAgentBehaviorReplayReadModel();
        $agentBehaviorMcpReport = $this->scanAgentBehaviorMcpReport();
        $agentBehaviorSelfImprovementReview = $this->scanAgentBehaviorSelfImprovementReview();
        $agentBehaviorDirectSurfaces = $this->scanAgentBehaviorDirectSurfaces();
        $agentBehaviorDedicatedCuratorFlow = $this->scanAgentBehaviorDedicatedCuratorFlow();
        $agentBehaviorCuratorFilterSurface = $this->scanAgentBehaviorCuratorFilterSurface();
        $agentBehaviorRecurringSchedule = $this->scanAgentBehaviorRecurringSchedule();
        $agentBehaviorProposalGovernance = $this->scanAgentBehaviorProposalGovernance();
        $toolTierHotPath = $this->scanToolTierHotPathPolicy();
        $providerMemoryPrivacy = $this->scanProviderMemoryPrivacy();
        $sloObservability = $this->scanSloObservability();
        $kernelPipelineContract = $this->scanKernelPipelineContract();
        $repairLoopContract = $this->scanRepairLoopContract();
        $mcpDomainCatalogParity = $this->scanMcpDomainCatalogParity();
        $cliFixDevRepairAlias = $this->scanCliFixDevRepairAlias();
        $cliContinueDevResumeAlias = $this->scanCliContinueDevResumeAlias();
        $cliForgeProgrammingHarnessContract = $this->scanCliForgeProgrammingHarnessContract();
        $chatDevProgrammingContract = $this->scanChatDevProgrammingContract();
        $surfaceAliasCanonicalization = $this->scanSurfaceAliasCanonicalization();
        $decideModelSelectionContract = $this->scanDecideModelSelectionContract();
        $cliDevModelSelectionContract = $this->scanCliDevModelSelectionContract();
        $chatModelSelectionContract = $this->scanChatModelSelectionContract();
        $kernelModelSelectionContractFactory = $this->scanKernelModelSelectionContractFactory();
        $programmingSurfaceContractFactory = $this->scanProgrammingSurfaceContractFactory();
        $chatProgrammingContractFactory = $this->scanChatProgrammingContractFactory();
        $continueResumeContractFactory = $this->scanContinueResumeContractFactory();
        $fixContractFactory = $this->scanFixContractFactory();
        $kernelPipelineHealthReadModel = $this->scanKernelPipelineHealthReadModel();
        $architectureValidationSurface = $this->scanArchitectureValidationSurface();
        $architectureValidationObservability = $this->scanArchitectureValidationObservability();
        $architectureValidationContractParity = $this->scanArchitectureValidationContractParity();
        $architectureValidationMcpTool = $this->scanArchitectureValidationMcpTool();
        $selfImprovementArchitectureValidationReview = $this->scanSelfImprovementArchitectureValidationReview();
        $selfImprovementArchitectureAuditSchedule = $this->scanSelfImprovementArchitectureAuditSchedule();
        $selfImprovementFlowCadenceContract = $this->scanSelfImprovementFlowCadenceContract();
        $selfImprovementCommandNextRunContract = $this->scanSelfImprovementCommandNextRunContract();
        $selfImprovementScheduleMcpTool = $this->scanSelfImprovementScheduleMcpTool();
        $selfImprovementScheduleHealthReview = $this->scanSelfImprovementScheduleHealthReview();
        $selfImprovementScheduleHealthLedgerEvent = $this->scanSelfImprovementScheduleHealthLedgerEvent();
        $selfImprovementScheduleReplayReadModel = $this->scanSelfImprovementScheduleReplayReadModel();
        $selfImprovementScheduleReplaySurfaces = $this->scanSelfImprovementScheduleReplaySurfaces();
        $selfImprovementScheduleReplayMcpTool = $this->scanSelfImprovementScheduleReplayMcpTool();
        $selfImprovementScheduleReplayReview = $this->scanSelfImprovementScheduleReplayReview();
        $selfImprovementScheduleReplayReviewSignal = $this->scanSelfImprovementScheduleReplayReviewSignal();
        $selfImprovementScheduleReplayReviewSignalSurfaces = $this->scanSelfImprovementScheduleReplayReviewSignalSurfaces();
        $kernelPipelineReviewSignal = $this->scanKernelPipelineReviewSignal();
        $repairLoopReviewSignal = $this->scanRepairLoopReviewSignal();
        $sloReviewSignal = $this->scanSloReviewSignal();
        $sloMcpTool = $this->scanSloMcpTool();
        $kernelPipelineMcpTool = $this->scanKernelPipelineMcpTool();
        $repairLoopMcpTool = $this->scanRepairLoopMcpTool();
        $repairLoopUnavailableReviewSignal = $this->scanRepairLoopUnavailableReviewSignal();
        $sloUnavailableReviewSignal = $this->scanSloUnavailableReviewSignal();
        $mcpReplayUnavailableReviewSignal = $this->scanMcpReplayUnavailableReviewSignal();
        $scheduleReplayUnavailableShapeParity = $this->scanScheduleReplayUnavailableShapeParity();
        $mcpReplayWindowContract = $this->scanMcpReplayWindowContract();
        $mcpReplayFilterContract = $this->scanMcpReplayFilterContract();
        $replayReportInputContract = $this->scanReplayReportInputContract();
        $observabilityReplayInputContract = $this->scanObservabilityReplayInputContract();
        $replayReportValidationLimitContract = $this->scanReplayReportValidationLimitContract();
        $selfImprovementRuntimeWindowContract = $this->scanSelfImprovementRuntimeWindowContract();
        $selfImprovementScheduleWindowContract = $this->scanSelfImprovementScheduleWindowContract();
        $selfImprovementOrchestratorWindowContract = $this->scanSelfImprovementOrchestratorWindowContract();
        $telemetryWindowInputContract = $this->scanTelemetryWindowInputContract();
        $runtimeBudgetWindowContract = $this->scanRuntimeBudgetWindowContract();
        $ledgerEnvelopeInputContract = $this->scanLedgerEnvelopeInputContract();
        $ledgerEnvelopeReportContract = $this->scanLedgerEnvelopeReportContract();
        $telemetryListLimitContract = $this->scanTelemetryListLimitContract();
        $programmingIterationPolicyContract = $this->scanProgrammingIterationPolicyContract();
        $openBrainMcpInputContract = $this->scanOpenBrainMcpInputContract();
        $memoryQueryInputContract = $this->scanMemoryQueryInputContract();
        $providerProjectionAuditInputContract = $this->scanProviderProjectionAuditInputContract();
        $conversationContextInputContract = $this->scanConversationContextInputContract();
        $retrievalRankInputContract = $this->scanRetrievalRankInputContract();
        $atlasVaultCommandInputContract = $this->scanAtlasVaultCommandInputContract();
        $memoryRecallInputContract = $this->scanMemoryRecallInputContract();
        $contextPackMemoryInputContract = $this->scanContextPackMemoryInputContract();
        $semanticContextInputContract = $this->scanSemanticContextInputContract();
        $providerProjectionInputContract = $this->scanProviderProjectionInputContract();
        $testCommandInputContract = $this->scanTestCommandInputContract();
        $engineeringHarnessRunnerInputContract = $this->scanEngineeringHarnessRunnerInputContract();
        $engineeringHarnessabilityInputContract = $this->scanEngineeringHarnessabilityInputContract();
        $engineeringDockerHarnessInputContract = $this->scanEngineeringDockerHarnessInputContract();
        $engineeringTestMatrixInputContract = $this->scanEngineeringTestMatrixInputContract();
        $engineeringClaudeCodeBaselineInputContract = $this->scanEngineeringClaudeCodeBaselineInputContract();
        $engineeringBenchmarkInputContract = $this->scanEngineeringBenchmarkInputContract();
        $engineeringContextIntelligenceInputContract = $this->scanEngineeringContextIntelligenceInputContract();
        $cliLimitInputContract = $this->scanCliLimitInputContract();
        $schedulerInputContract = $this->scanSchedulerInputContract();
        $selfImprovementInputContract = $this->scanSelfImprovementInputContract();
        $providerUsagePerformanceContract = $this->scanProviderUsagePerformanceContract();
        $contextPackManifestReflectionContract = $this->scanContextPackManifestReflectionContract();
        $contextRetrievalRouterContract = $this->scanContextRetrievalRouterContract();
        $openBrainRetrievalPlanSummaryContract = $this->scanOpenBrainRetrievalPlanSummaryContract();
        $retrievalRequiredSourceAvailabilityContract = $this->scanRetrievalRequiredSourceAvailabilityContract();
        $retrievalReviewSignalNextActionContract = $this->scanRetrievalReviewSignalNextActionContract();
        $openBrainRetrievalSelfImprovementContract = $this->scanOpenBrainRetrievalSelfImprovementContract();
        $learningProposedReviewSignalProjectionContract = $this->scanLearningProposedReviewSignalProjectionContract();
        $proposalInboxReviewSignalContract = $this->scanProposalInboxReviewSignalContract();
        $learningProposedInboxLinkContract = $this->scanLearningProposedInboxLinkContract();
        $operationCompletedInboxRefsContract = $this->scanOperationCompletedInboxRefsContract();
        $scheduleReplayInboxRefsContract = $this->scanScheduleReplayInboxRefsContract();
        $scheduleReplayInboxRefsSurfaceParity = $this->scanScheduleReplayInboxRefsSurfaceParity();
        $scheduleReplayInboxItemHydration = $this->scanScheduleReplayInboxItemHydration();
        $scheduleReplayInboxItemHydrationSurfaceParity = $this->scanScheduleReplayInboxItemHydrationSurfaceParity();
        $scheduleReplayInboxHydrationGapSignal = $this->scanScheduleReplayInboxHydrationGapSignal();
        $selfImprovementScheduleReplayInboxGapFinding = $this->scanSelfImprovementScheduleReplayInboxGapFinding();
        $selfImprovementScheduleReplayInboxGapEmission = $this->scanSelfImprovementScheduleReplayInboxGapEmission();
        $proposalInboxReviewSignalSeverity = $this->scanProposalInboxReviewSignalSeverity();
        $proposalReviewActionContract = $this->scanProposalReviewActionContract();
        $cliInboxReviewActionResultParity = $this->scanCliInboxReviewActionResultParity();
        $inboxActionEvidenceLedgerContract = $this->scanInboxActionEvidenceLedgerContract();
        $inboxActionReplayReadModel = $this->scanInboxActionReplayReadModel();
        $inboxActionMcpReport = $this->scanInboxActionMcpReport();
        $selfImprovementInboxActionReplayReview = $this->scanSelfImprovementInboxActionReplayReview();
        $observabilityInboxActionReplay = $this->scanObservabilityInboxActionReplay();
        $inboxActionReportSurfaces = $this->scanInboxActionReportSurfaces();
        $architectureValidatePostAp98HumanOutput = $this->scanArchitectureValidatePostAp98HumanOutput();
        $cliHelpArchitectureOperationsDiscovery = $this->scanCliHelpArchitectureOperationsDiscovery();
        $architectureOperationsSharedCatalog = $this->scanArchitectureOperationsSharedCatalog();
        $architectureOperationsMcpTool = $this->scanArchitectureOperationsMcpTool();
        $architectureOperationsDirectSurfaces = $this->scanArchitectureOperationsDirectSurfaces();
        $selfImprovementArchitectureOperationsReview = $this->scanSelfImprovementArchitectureOperationsReview();
        $architectureOperationsMetadataContract = $this->scanArchitectureOperationsMetadataContract();
        $architectureOperationsFilterContract = $this->scanArchitectureOperationsFilterContract();
        $sessionBootstrapDocsSplitPlanContract = $this->scanSessionBootstrapDocsSplitPlanContract();
        $sessionBootstrapArchitectureOperationsContract = $this->scanSessionBootstrapArchitectureOperationsContract();
        $featurePlacementArchitectureOperationsContract = $this->scanFeaturePlacementArchitectureOperationsContract();
        $architectureReadinessSnapshot = $this->scanArchitectureReadinessSnapshot();
        $architectureReadinessMcpTool = $this->scanArchitectureReadinessMcpTool();
        $apAgentWorkflowContracts = $this->scanApAgentWorkflowContracts();

        return [
            'ok' => $surfaceProviderBypass === []
                && $surfaceContextBypass === []
                && $decisionReceiptPropagation === []
                && $decisionReceiptRuntimeGuard === []
                && $decisionReceiptHashRuntimeGuard === []
                && $decisionReceiptDeterminismTest === []
                && $decisionReceiptChainReplay === []
                && $decisionReceiptReplaySurfaces === []
                && $decisionReceiptReplayCuratorReview === []
                && $decisionReceiptReplayInboxEmission === []
                && $ledgerReplayCommandSurface === []
                && $ledgerProjectionRegistryContract === []
                && $ledgerProjectionInboxAction === []
                && $ledgerProjectionCuratorActionEmission === []
                && $rivalsReviewInboxActionContract === []
                && $documentationHealthCuratorReview === []
                && $providerCostRateInboxReplay === []
                && $agentBehaviorIdentityFragment === []
                && $agentBehaviorExecutionPlan === []
                && $agentBehaviorQualityGate === []
                && $agentBehaviorReviewActionSurface === []
                && $programmingPlanAgentBehaviorContract === []
                && $programmingHarnessAgentBehaviorContract === []
                && $agentBehaviorEvidenceLedger === []
                && $agentBehaviorReplayReadModel === []
                && $agentBehaviorMcpReport === []
                && $agentBehaviorSelfImprovementReview === []
                && $agentBehaviorDirectSurfaces === []
                && $agentBehaviorDedicatedCuratorFlow === []
                && $agentBehaviorCuratorFilterSurface === []
                && $agentBehaviorRecurringSchedule === []
                && $agentBehaviorProposalGovernance === []
                && $providerDriverBypass === []
                && $toolTierHotPath === []
                && $providerMemoryPrivacy === []
                && $sloObservability === []
                && $kernelPipelineContract === []
                && $repairLoopContract === []
                && $mcpDomainCatalogParity === []
                && $cliFixDevRepairAlias === []
                && $cliContinueDevResumeAlias === []
                && $cliForgeProgrammingHarnessContract === []
                && $chatDevProgrammingContract === []
                && $surfaceAliasCanonicalization === []
                && $decideModelSelectionContract === []
                && $cliDevModelSelectionContract === []
                && $chatModelSelectionContract === []
                && $kernelModelSelectionContractFactory === []
                && $programmingSurfaceContractFactory === []
                && $chatProgrammingContractFactory === []
                && $continueResumeContractFactory === []
                && $fixContractFactory === []
                && $kernelPipelineHealthReadModel === []
                && $architectureValidationSurface === []
                && $architectureValidationObservability === []
                && $architectureValidationContractParity === []
                && $architectureValidationMcpTool === []
                && $selfImprovementArchitectureValidationReview === []
                && $selfImprovementArchitectureAuditSchedule === []
                && $selfImprovementFlowCadenceContract === []
                && $selfImprovementCommandNextRunContract === []
                && $selfImprovementScheduleMcpTool === []
                && $selfImprovementScheduleHealthReview === []
                && $selfImprovementScheduleHealthLedgerEvent === []
                && $selfImprovementScheduleReplayReadModel === []
                && $selfImprovementScheduleReplaySurfaces === []
                && $selfImprovementScheduleReplayMcpTool === []
                && $selfImprovementScheduleReplayReview === []
                && $selfImprovementScheduleReplayReviewSignal === []
                && $selfImprovementScheduleReplayReviewSignalSurfaces === []
                && $kernelPipelineReviewSignal === []
                && $repairLoopReviewSignal === []
                && $sloReviewSignal === []
                && $sloMcpTool === []
                && $kernelPipelineMcpTool === []
                && $repairLoopMcpTool === []
                && $repairLoopUnavailableReviewSignal === []
                && $sloUnavailableReviewSignal === []
                && $mcpReplayUnavailableReviewSignal === []
                && $scheduleReplayUnavailableShapeParity === []
                && $mcpReplayWindowContract === []
                && $mcpReplayFilterContract === []
                && $replayReportInputContract === []
                && $observabilityReplayInputContract === []
                && $replayReportValidationLimitContract === []
                && $selfImprovementRuntimeWindowContract === []
                && $selfImprovementScheduleWindowContract === []
                && $selfImprovementOrchestratorWindowContract === []
                && $telemetryWindowInputContract === []
                && $runtimeBudgetWindowContract === []
                && $ledgerEnvelopeInputContract === []
                && $ledgerEnvelopeReportContract === []
                && $telemetryListLimitContract === []
                && $programmingIterationPolicyContract === []
                && $openBrainMcpInputContract === []
                && $memoryQueryInputContract === []
                && $providerProjectionAuditInputContract === []
                && $conversationContextInputContract === []
                && $retrievalRankInputContract === []
                && $atlasVaultCommandInputContract === []
                && $memoryRecallInputContract === []
                && $contextPackMemoryInputContract === []
                && $semanticContextInputContract === []
                && $providerProjectionInputContract === []
                && $testCommandInputContract === []
                && $engineeringHarnessRunnerInputContract === []
                && $engineeringHarnessabilityInputContract === []
                && $engineeringDockerHarnessInputContract === []
                && $engineeringTestMatrixInputContract === []
                && $engineeringClaudeCodeBaselineInputContract === []
                && $engineeringBenchmarkInputContract === []
                && $engineeringContextIntelligenceInputContract === []
                && $cliLimitInputContract === []
                && $schedulerInputContract === []
                && $selfImprovementInputContract === []
                && $providerUsagePerformanceContract === []
                && $contextPackManifestReflectionContract === []
                && $contextRetrievalRouterContract === []
                && $openBrainRetrievalPlanSummaryContract === []
                && $retrievalRequiredSourceAvailabilityContract === []
                && $retrievalReviewSignalNextActionContract === []
                && $openBrainRetrievalSelfImprovementContract === []
                && $learningProposedReviewSignalProjectionContract === []
                && $proposalInboxReviewSignalContract === []
                && $learningProposedInboxLinkContract === []
                && $operationCompletedInboxRefsContract === []
                && $scheduleReplayInboxRefsContract === []
                && $scheduleReplayInboxRefsSurfaceParity === []
                && $scheduleReplayInboxItemHydration === []
                && $scheduleReplayInboxItemHydrationSurfaceParity === []
                && $scheduleReplayInboxHydrationGapSignal === []
                && $selfImprovementScheduleReplayInboxGapFinding === []
                && $selfImprovementScheduleReplayInboxGapEmission === []
                && $proposalInboxReviewSignalSeverity === []
                && $proposalReviewActionContract === []
                && $cliInboxReviewActionResultParity === []
                && $inboxActionEvidenceLedgerContract === []
                && $inboxActionReplayReadModel === []
                && $inboxActionMcpReport === []
                && $selfImprovementInboxActionReplayReview === []
                && $observabilityInboxActionReplay === []
                && $inboxActionReportSurfaces === []
                && $architectureValidatePostAp98HumanOutput === []
                && $cliHelpArchitectureOperationsDiscovery === []
                && $architectureOperationsSharedCatalog === []
                && $architectureOperationsMcpTool === []
                && $architectureOperationsDirectSurfaces === []
                && $selfImprovementArchitectureOperationsReview === []
                && $architectureOperationsMetadataContract === []
                && $architectureOperationsFilterContract === []
                && $sessionBootstrapDocsSplitPlanContract === []
                && $sessionBootstrapArchitectureOperationsContract === []
                && $featurePlacementArchitectureOperationsContract === []
                && $architectureReadinessSnapshot === []
                && $architectureReadinessMcpTool === []
                && $apAgentWorkflowContracts === [],
            'ap1_surface_provider_bypass' => [
                'valid' => $surfaceProviderBypass === [],
                'violations' => $surfaceProviderBypass,
            ],
            'ap2_surface_context_bypass' => [
                'valid' => $surfaceContextBypass === [],
                'violations' => $surfaceContextBypass,
            ],
            'ap6_decision_receipt_propagation' => [
                'valid' => $decisionReceiptPropagation === [],
                'violations' => $decisionReceiptPropagation,
            ],
            'ap13_decision_receipt_runtime_guard' => [
                'valid' => $decisionReceiptRuntimeGuard === [],
                'violations' => $decisionReceiptRuntimeGuard,
            ],
            'ap134_decision_receipt_hash_runtime_guard' => [
                'valid' => $decisionReceiptHashRuntimeGuard === [],
                'violations' => $decisionReceiptHashRuntimeGuard,
            ],
            'ap135_decision_receipt_determinism_test' => [
                'valid' => $decisionReceiptDeterminismTest === [],
                'violations' => $decisionReceiptDeterminismTest,
            ],
            'ap136_decision_receipt_chain_replay' => [
                'valid' => $decisionReceiptChainReplay === [],
                'violations' => $decisionReceiptChainReplay,
            ],
            'ap137_decision_receipt_replay_surfaces' => [
                'valid' => $decisionReceiptReplaySurfaces === [],
                'violations' => $decisionReceiptReplaySurfaces,
            ],
            'ap138_decision_receipt_replay_curator_review' => [
                'valid' => $decisionReceiptReplayCuratorReview === [],
                'violations' => $decisionReceiptReplayCuratorReview,
            ],
            'ap139_decision_receipt_replay_inbox_emission' => [
                'valid' => $decisionReceiptReplayInboxEmission === [],
                'violations' => $decisionReceiptReplayInboxEmission,
            ],
            'ap140_ledger_replay_command_surface' => [
                'valid' => $ledgerReplayCommandSurface === [],
                'violations' => $ledgerReplayCommandSurface,
            ],
            'ap141_ledger_projection_registry_contract' => [
                'valid' => $ledgerProjectionRegistryContract === [],
                'violations' => $ledgerProjectionRegistryContract,
            ],
            'ap142_ledger_projection_inbox_action' => [
                'valid' => $ledgerProjectionInboxAction === [],
                'violations' => $ledgerProjectionInboxAction,
            ],
            'ap143_ledger_projection_curator_action_emission' => [
                'valid' => $ledgerProjectionCuratorActionEmission === [],
                'violations' => $ledgerProjectionCuratorActionEmission,
            ],
            'ap144_rivals_review_inbox_action_contract' => [
                'valid' => $rivalsReviewInboxActionContract === [],
                'violations' => $rivalsReviewInboxActionContract,
            ],
            'ap145_documentation_health_curator_review' => [
                'valid' => $documentationHealthCuratorReview === [],
                'violations' => $documentationHealthCuratorReview,
            ],
            'ap146_provider_cost_rate_inbox_replay' => [
                'valid' => $providerCostRateInboxReplay === [],
                'violations' => $providerCostRateInboxReplay,
            ],
            'ap148_agent_behavior_identity_fragment' => [
                'valid' => $agentBehaviorIdentityFragment === [],
                'violations' => $agentBehaviorIdentityFragment,
            ],
            'ap149_agent_behavior_execution_plan' => [
                'valid' => $agentBehaviorExecutionPlan === [],
                'violations' => $agentBehaviorExecutionPlan,
            ],
            'ap150_agent_behavior_quality_gate' => [
                'valid' => $agentBehaviorQualityGate === [],
                'violations' => $agentBehaviorQualityGate,
            ],
            'ap151_agent_behavior_review_action_surface' => [
                'valid' => $agentBehaviorReviewActionSurface === [],
                'violations' => $agentBehaviorReviewActionSurface,
            ],
            'ap152_programming_plan_agent_behavior_contract' => [
                'valid' => $programmingPlanAgentBehaviorContract === [],
                'violations' => $programmingPlanAgentBehaviorContract,
            ],
            'ap153_programming_harness_agent_behavior_contract' => [
                'valid' => $programmingHarnessAgentBehaviorContract === [],
                'violations' => $programmingHarnessAgentBehaviorContract,
            ],
            'ap154_agent_behavior_evidence_ledger' => [
                'valid' => $agentBehaviorEvidenceLedger === [],
                'violations' => $agentBehaviorEvidenceLedger,
            ],
            'ap155_agent_behavior_replay_read_model' => [
                'valid' => $agentBehaviorReplayReadModel === [],
                'violations' => $agentBehaviorReplayReadModel,
            ],
            'ap156_agent_behavior_mcp_report' => [
                'valid' => $agentBehaviorMcpReport === [],
                'violations' => $agentBehaviorMcpReport,
            ],
            'ap157_agent_behavior_self_improvement_review' => [
                'valid' => $agentBehaviorSelfImprovementReview === [],
                'violations' => $agentBehaviorSelfImprovementReview,
            ],
            'ap158_agent_behavior_direct_surfaces' => [
                'valid' => $agentBehaviorDirectSurfaces === [],
                'violations' => $agentBehaviorDirectSurfaces,
            ],
            'ap159_agent_behavior_dedicated_curator_flow' => [
                'valid' => $agentBehaviorDedicatedCuratorFlow === [],
                'violations' => $agentBehaviorDedicatedCuratorFlow,
            ],
            'ap160_agent_behavior_curator_filter_surface' => [
                'valid' => $agentBehaviorCuratorFilterSurface === [],
                'violations' => $agentBehaviorCuratorFilterSurface,
            ],
            'ap161_agent_behavior_recurring_schedule' => [
                'valid' => $agentBehaviorRecurringSchedule === [],
                'violations' => $agentBehaviorRecurringSchedule,
            ],
            'ap162_agent_behavior_proposal_governance' => [
                'valid' => $agentBehaviorProposalGovernance === [],
                'violations' => $agentBehaviorProposalGovernance,
            ],
            'ap12_provider_driver_identity_bypass' => [
                'valid' => $providerDriverBypass === [],
                'violations' => $providerDriverBypass,
            ],
            'ap14_tool_tier_hot_path' => [
                'valid' => $toolTierHotPath === [],
                'violations' => $toolTierHotPath,
            ],
            'ap15_provider_memory_privacy' => [
                'valid' => $providerMemoryPrivacy === [],
                'violations' => $providerMemoryPrivacy,
            ],
            'ap16_slo_observability' => [
                'valid' => $sloObservability === [],
                'violations' => $sloObservability,
            ],
            'ap17_kernel_pipeline_contract' => [
                'valid' => $kernelPipelineContract === [],
                'violations' => $kernelPipelineContract,
            ],
            'ap18_repair_loop_contract' => [
                'valid' => $repairLoopContract === [],
                'violations' => $repairLoopContract,
            ],
            'ap19_mcp_domain_catalog_parity' => [
                'valid' => $mcpDomainCatalogParity === [],
                'violations' => $mcpDomainCatalogParity,
            ],
            'ap20_cli_fix_dev_repair_alias' => [
                'valid' => $cliFixDevRepairAlias === [],
                'violations' => $cliFixDevRepairAlias,
            ],
            'ap21_cli_continue_dev_resume_alias' => [
                'valid' => $cliContinueDevResumeAlias === [],
                'violations' => $cliContinueDevResumeAlias,
            ],
            'ap22_cli_forge_programming_harness_contract' => [
                'valid' => $cliForgeProgrammingHarnessContract === [],
                'violations' => $cliForgeProgrammingHarnessContract,
            ],
            'ap23_chat_dev_programming_contract' => [
                'valid' => $chatDevProgrammingContract === [],
                'violations' => $chatDevProgrammingContract,
            ],
            'ap24_surface_alias_canonicalization' => [
                'valid' => $surfaceAliasCanonicalization === [],
                'violations' => $surfaceAliasCanonicalization,
            ],
            'ap25_decide_model_selection_contract' => [
                'valid' => $decideModelSelectionContract === [],
                'violations' => $decideModelSelectionContract,
            ],
            'ap26_cli_dev_model_selection_contract' => [
                'valid' => $cliDevModelSelectionContract === [],
                'violations' => $cliDevModelSelectionContract,
            ],
            'ap27_chat_model_selection_contract' => [
                'valid' => $chatModelSelectionContract === [],
                'violations' => $chatModelSelectionContract,
            ],
            'ap28_kernel_model_selection_contract_factory' => [
                'valid' => $kernelModelSelectionContractFactory === [],
                'violations' => $kernelModelSelectionContractFactory,
            ],
            'ap29_programming_surface_contract_factory' => [
                'valid' => $programmingSurfaceContractFactory === [],
                'violations' => $programmingSurfaceContractFactory,
            ],
            'ap30_chat_programming_contract_factory' => [
                'valid' => $chatProgrammingContractFactory === [],
                'violations' => $chatProgrammingContractFactory,
            ],
            'ap31_continue_resume_contract_factory' => [
                'valid' => $continueResumeContractFactory === [],
                'violations' => $continueResumeContractFactory,
            ],
            'ap32_fix_contract_factory' => [
                'valid' => $fixContractFactory === [],
                'violations' => $fixContractFactory,
            ],
            'ap36_kernel_pipeline_health_read_model' => [
                'valid' => $kernelPipelineHealthReadModel === [],
                'violations' => $kernelPipelineHealthReadModel,
            ],
            'ap37_architecture_validation_surface' => [
                'valid' => $architectureValidationSurface === [],
                'violations' => $architectureValidationSurface,
            ],
            'ap38_architecture_validation_observability' => [
                'valid' => $architectureValidationObservability === [],
                'violations' => $architectureValidationObservability,
            ],
            'ap39_architecture_validation_contract_parity' => [
                'valid' => $architectureValidationContractParity === [],
                'violations' => $architectureValidationContractParity,
            ],
            'ap40_architecture_validation_mcp_tool' => [
                'valid' => $architectureValidationMcpTool === [],
                'violations' => $architectureValidationMcpTool,
            ],
            'ap41_self_improvement_architecture_validation_review' => [
                'valid' => $selfImprovementArchitectureValidationReview === [],
                'violations' => $selfImprovementArchitectureValidationReview,
            ],
            'ap42_self_improvement_architecture_audit_schedule' => [
                'valid' => $selfImprovementArchitectureAuditSchedule === [],
                'violations' => $selfImprovementArchitectureAuditSchedule,
            ],
            'ap43_self_improvement_flow_cadence_contract' => [
                'valid' => $selfImprovementFlowCadenceContract === [],
                'violations' => $selfImprovementFlowCadenceContract,
            ],
            'ap44_self_improvement_command_next_run_contract' => [
                'valid' => $selfImprovementCommandNextRunContract === [],
                'violations' => $selfImprovementCommandNextRunContract,
            ],
            'ap45_self_improvement_schedule_mcp_tool' => [
                'valid' => $selfImprovementScheduleMcpTool === [],
                'violations' => $selfImprovementScheduleMcpTool,
            ],
            'ap46_self_improvement_schedule_health_review' => [
                'valid' => $selfImprovementScheduleHealthReview === [],
                'violations' => $selfImprovementScheduleHealthReview,
            ],
            'ap47_self_improvement_schedule_health_ledger_event' => [
                'valid' => $selfImprovementScheduleHealthLedgerEvent === [],
                'violations' => $selfImprovementScheduleHealthLedgerEvent,
            ],
            'ap48_self_improvement_schedule_replay_read_model' => [
                'valid' => $selfImprovementScheduleReplayReadModel === [],
                'violations' => $selfImprovementScheduleReplayReadModel,
            ],
            'ap49_self_improvement_schedule_replay_surfaces' => [
                'valid' => $selfImprovementScheduleReplaySurfaces === [],
                'violations' => $selfImprovementScheduleReplaySurfaces,
            ],
            'ap50_self_improvement_schedule_replay_mcp_tool' => [
                'valid' => $selfImprovementScheduleReplayMcpTool === [],
                'violations' => $selfImprovementScheduleReplayMcpTool,
            ],
            'ap51_self_improvement_schedule_replay_review' => [
                'valid' => $selfImprovementScheduleReplayReview === [],
                'violations' => $selfImprovementScheduleReplayReview,
            ],
            'ap52_self_improvement_schedule_replay_review_signal' => [
                'valid' => $selfImprovementScheduleReplayReviewSignal === [],
                'violations' => $selfImprovementScheduleReplayReviewSignal,
            ],
            'ap53_self_improvement_schedule_replay_review_signal_surfaces' => [
                'valid' => $selfImprovementScheduleReplayReviewSignalSurfaces === [],
                'violations' => $selfImprovementScheduleReplayReviewSignalSurfaces,
            ],
            'ap54_kernel_pipeline_review_signal' => [
                'valid' => $kernelPipelineReviewSignal === [],
                'violations' => $kernelPipelineReviewSignal,
            ],
            'ap55_repair_loop_review_signal' => [
                'valid' => $repairLoopReviewSignal === [],
                'violations' => $repairLoopReviewSignal,
            ],
            'ap56_slo_review_signal' => [
                'valid' => $sloReviewSignal === [],
                'violations' => $sloReviewSignal,
            ],
            'ap57_slo_mcp_tool' => [
                'valid' => $sloMcpTool === [],
                'violations' => $sloMcpTool,
            ],
            'ap58_kernel_pipeline_mcp_tool' => [
                'valid' => $kernelPipelineMcpTool === [],
                'violations' => $kernelPipelineMcpTool,
            ],
            'ap59_repair_loop_mcp_tool' => [
                'valid' => $repairLoopMcpTool === [],
                'violations' => $repairLoopMcpTool,
            ],
            'ap60_repair_loop_unavailable_review_signal' => [
                'valid' => $repairLoopUnavailableReviewSignal === [],
                'violations' => $repairLoopUnavailableReviewSignal,
            ],
            'ap61_slo_unavailable_review_signal' => [
                'valid' => $sloUnavailableReviewSignal === [],
                'violations' => $sloUnavailableReviewSignal,
            ],
            'ap62_mcp_replay_unavailable_review_signal' => [
                'valid' => $mcpReplayUnavailableReviewSignal === [],
                'violations' => $mcpReplayUnavailableReviewSignal,
            ],
            'ap63_schedule_replay_unavailable_shape_parity' => [
                'valid' => $scheduleReplayUnavailableShapeParity === [],
                'violations' => $scheduleReplayUnavailableShapeParity,
            ],
            'ap64_mcp_replay_window_contract' => [
                'valid' => $mcpReplayWindowContract === [],
                'violations' => $mcpReplayWindowContract,
            ],
            'ap65_mcp_replay_filter_contract' => [
                'valid' => $mcpReplayFilterContract === [],
                'violations' => $mcpReplayFilterContract,
            ],
            'ap66_replay_report_input_contract' => [
                'valid' => $replayReportInputContract === [],
                'violations' => $replayReportInputContract,
            ],
            'ap67_observability_replay_input_contract' => [
                'valid' => $observabilityReplayInputContract === [],
                'violations' => $observabilityReplayInputContract,
            ],
            'ap68_replay_report_validation_limit_contract' => [
                'valid' => $replayReportValidationLimitContract === [],
                'violations' => $replayReportValidationLimitContract,
            ],
            'ap69_self_improvement_runtime_window_contract' => [
                'valid' => $selfImprovementRuntimeWindowContract === [],
                'violations' => $selfImprovementRuntimeWindowContract,
            ],
            'ap70_self_improvement_schedule_window_contract' => [
                'valid' => $selfImprovementScheduleWindowContract === [],
                'violations' => $selfImprovementScheduleWindowContract,
            ],
            'ap71_self_improvement_orchestrator_window_contract' => [
                'valid' => $selfImprovementOrchestratorWindowContract === [],
                'violations' => $selfImprovementOrchestratorWindowContract,
            ],
            'ap72_telemetry_window_input_contract' => [
                'valid' => $telemetryWindowInputContract === [],
                'violations' => $telemetryWindowInputContract,
            ],
            'ap73_runtime_budget_window_contract' => [
                'valid' => $runtimeBudgetWindowContract === [],
                'violations' => $runtimeBudgetWindowContract,
            ],
            'ap74_ledger_envelope_input_contract' => [
                'valid' => $ledgerEnvelopeInputContract === [],
                'violations' => $ledgerEnvelopeInputContract,
            ],
            'ap75_ledger_envelope_report_contract' => [
                'valid' => $ledgerEnvelopeReportContract === [],
                'violations' => $ledgerEnvelopeReportContract,
            ],
            'ap76_telemetry_list_limit_contract' => [
                'valid' => $telemetryListLimitContract === [],
                'violations' => $telemetryListLimitContract,
            ],
            'ap77_programming_iteration_policy_contract' => [
                'valid' => $programmingIterationPolicyContract === [],
                'violations' => $programmingIterationPolicyContract,
            ],
            'ap78_open_brain_mcp_input_contract' => [
                'valid' => $openBrainMcpInputContract === [],
                'violations' => $openBrainMcpInputContract,
            ],
            'ap79_memory_query_input_contract' => [
                'valid' => $memoryQueryInputContract === [],
                'violations' => $memoryQueryInputContract,
            ],
            'ap80_provider_projection_audit_input_contract' => [
                'valid' => $providerProjectionAuditInputContract === [],
                'violations' => $providerProjectionAuditInputContract,
            ],
            'ap81_conversation_context_input_contract' => [
                'valid' => $conversationContextInputContract === [],
                'violations' => $conversationContextInputContract,
            ],
            'ap82_retrieval_rank_input_contract' => [
                'valid' => $retrievalRankInputContract === [],
                'violations' => $retrievalRankInputContract,
            ],
            'ap83_atlas_vault_command_input_contract' => [
                'valid' => $atlasVaultCommandInputContract === [],
                'violations' => $atlasVaultCommandInputContract,
            ],
            'ap84_memory_recall_input_contract' => [
                'valid' => $memoryRecallInputContract === [],
                'violations' => $memoryRecallInputContract,
            ],
            'ap85_context_pack_memory_input_contract' => [
                'valid' => $contextPackMemoryInputContract === [],
                'violations' => $contextPackMemoryInputContract,
            ],
            'ap86_semantic_context_input_contract' => [
                'valid' => $semanticContextInputContract === [],
                'violations' => $semanticContextInputContract,
            ],
            'ap87_provider_projection_input_contract' => [
                'valid' => $providerProjectionInputContract === [],
                'violations' => $providerProjectionInputContract,
            ],
            'ap88_test_command_input_contract' => [
                'valid' => $testCommandInputContract === [],
                'violations' => $testCommandInputContract,
            ],
            'ap89_engineering_harness_runner_input_contract' => [
                'valid' => $engineeringHarnessRunnerInputContract === [],
                'violations' => $engineeringHarnessRunnerInputContract,
            ],
            'ap90_engineering_harnessability_input_contract' => [
                'valid' => $engineeringHarnessabilityInputContract === [],
                'violations' => $engineeringHarnessabilityInputContract,
            ],
            'ap91_engineering_docker_harness_input_contract' => [
                'valid' => $engineeringDockerHarnessInputContract === [],
                'violations' => $engineeringDockerHarnessInputContract,
            ],
            'ap92_engineering_test_matrix_input_contract' => [
                'valid' => $engineeringTestMatrixInputContract === [],
                'violations' => $engineeringTestMatrixInputContract,
            ],
            'ap93_engineering_claude_code_baseline_input_contract' => [
                'valid' => $engineeringClaudeCodeBaselineInputContract === [],
                'violations' => $engineeringClaudeCodeBaselineInputContract,
            ],
            'ap94_engineering_benchmark_input_contract' => [
                'valid' => $engineeringBenchmarkInputContract === [],
                'violations' => $engineeringBenchmarkInputContract,
            ],
            'ap95_engineering_context_intelligence_input_contract' => [
                'valid' => $engineeringContextIntelligenceInputContract === [],
                'violations' => $engineeringContextIntelligenceInputContract,
            ],
            'ap96_cli_limit_input_contract' => [
                'valid' => $cliLimitInputContract === [],
                'violations' => $cliLimitInputContract,
            ],
            'ap97_scheduler_input_contract' => [
                'valid' => $schedulerInputContract === [],
                'violations' => $schedulerInputContract,
            ],
            'ap98_self_improvement_input_contract' => [
                'valid' => $selfImprovementInputContract === [],
                'violations' => $selfImprovementInputContract,
            ],
            'ap99_provider_usage_performance_contract' => [
                'valid' => $providerUsagePerformanceContract === [],
                'violations' => $providerUsagePerformanceContract,
            ],
            'ap100_context_pack_manifest_reflection_contract' => [
                'valid' => $contextPackManifestReflectionContract === [],
                'violations' => $contextPackManifestReflectionContract,
            ],
            'ap101_context_retrieval_router_contract' => [
                'valid' => $contextRetrievalRouterContract === [],
                'violations' => $contextRetrievalRouterContract,
            ],
            'ap102_open_brain_retrieval_plan_summary_contract' => [
                'valid' => $openBrainRetrievalPlanSummaryContract === [],
                'violations' => $openBrainRetrievalPlanSummaryContract,
            ],
            'ap103_retrieval_required_source_availability_contract' => [
                'valid' => $retrievalRequiredSourceAvailabilityContract === [],
                'violations' => $retrievalRequiredSourceAvailabilityContract,
            ],
            'ap104_retrieval_review_signal_next_action_contract' => [
                'valid' => $retrievalReviewSignalNextActionContract === [],
                'violations' => $retrievalReviewSignalNextActionContract,
            ],
            'ap105_open_brain_retrieval_self_improvement_contract' => [
                'valid' => $openBrainRetrievalSelfImprovementContract === [],
                'violations' => $openBrainRetrievalSelfImprovementContract,
            ],
            'ap106_learning_proposed_review_signal_projection_contract' => [
                'valid' => $learningProposedReviewSignalProjectionContract === [],
                'violations' => $learningProposedReviewSignalProjectionContract,
            ],
            'ap107_proposal_inbox_review_signal_contract' => [
                'valid' => $proposalInboxReviewSignalContract === [],
                'violations' => $proposalInboxReviewSignalContract,
            ],
            'ap108_learning_proposed_inbox_link_contract' => [
                'valid' => $learningProposedInboxLinkContract === [],
                'violations' => $learningProposedInboxLinkContract,
            ],
            'ap109_operation_completed_inbox_refs_contract' => [
                'valid' => $operationCompletedInboxRefsContract === [],
                'violations' => $operationCompletedInboxRefsContract,
            ],
            'ap110_schedule_replay_inbox_refs_contract' => [
                'valid' => $scheduleReplayInboxRefsContract === [],
                'violations' => $scheduleReplayInboxRefsContract,
            ],
            'ap111_schedule_replay_inbox_refs_surface_parity' => [
                'valid' => $scheduleReplayInboxRefsSurfaceParity === [],
                'violations' => $scheduleReplayInboxRefsSurfaceParity,
            ],
            'ap112_schedule_replay_inbox_item_hydration' => [
                'valid' => $scheduleReplayInboxItemHydration === [],
                'violations' => $scheduleReplayInboxItemHydration,
            ],
            'ap113_schedule_replay_inbox_item_hydration_surface_parity' => [
                'valid' => $scheduleReplayInboxItemHydrationSurfaceParity === [],
                'violations' => $scheduleReplayInboxItemHydrationSurfaceParity,
            ],
            'ap114_schedule_replay_inbox_hydration_gap_signal' => [
                'valid' => $scheduleReplayInboxHydrationGapSignal === [],
                'violations' => $scheduleReplayInboxHydrationGapSignal,
            ],
            'ap115_self_improvement_schedule_replay_inbox_gap_finding' => [
                'valid' => $selfImprovementScheduleReplayInboxGapFinding === [],
                'violations' => $selfImprovementScheduleReplayInboxGapFinding,
            ],
            'ap116_self_improvement_schedule_replay_inbox_gap_emission' => [
                'valid' => $selfImprovementScheduleReplayInboxGapEmission === [],
                'violations' => $selfImprovementScheduleReplayInboxGapEmission,
            ],
            'ap117_proposal_inbox_review_signal_severity' => [
                'valid' => $proposalInboxReviewSignalSeverity === [],
                'violations' => $proposalInboxReviewSignalSeverity,
            ],
            'ap118_proposal_review_action_contract' => [
                'valid' => $proposalReviewActionContract === [],
                'violations' => $proposalReviewActionContract,
            ],
            'ap119_cli_inbox_review_action_result_parity' => [
                'valid' => $cliInboxReviewActionResultParity === [],
                'violations' => $cliInboxReviewActionResultParity,
            ],
            'ap120_inbox_action_evidence_ledger_contract' => [
                'valid' => $inboxActionEvidenceLedgerContract === [],
                'violations' => $inboxActionEvidenceLedgerContract,
            ],
            'ap121_inbox_action_replay_read_model' => [
                'valid' => $inboxActionReplayReadModel === [],
                'violations' => $inboxActionReplayReadModel,
            ],
            'ap122_inbox_action_mcp_report' => [
                'valid' => $inboxActionMcpReport === [],
                'violations' => $inboxActionMcpReport,
            ],
            'ap123_self_improvement_inbox_action_replay_review' => [
                'valid' => $selfImprovementInboxActionReplayReview === [],
                'violations' => $selfImprovementInboxActionReplayReview,
            ],
            'ap124_observability_inbox_action_replay' => [
                'valid' => $observabilityInboxActionReplay === [],
                'violations' => $observabilityInboxActionReplay,
            ],
            'ap125_inbox_action_report_surfaces' => [
                'valid' => $inboxActionReportSurfaces === [],
                'violations' => $inboxActionReportSurfaces,
            ],
            'ap126_architecture_validate_post_ap98_human_output' => [
                'valid' => $architectureValidatePostAp98HumanOutput === [],
                'violations' => $architectureValidatePostAp98HumanOutput,
            ],
            'ap127_cli_help_architecture_operations_discovery' => [
                'valid' => $cliHelpArchitectureOperationsDiscovery === [],
                'violations' => $cliHelpArchitectureOperationsDiscovery,
            ],
            'ap128_architecture_operations_shared_catalog' => [
                'valid' => $architectureOperationsSharedCatalog === [],
                'violations' => $architectureOperationsSharedCatalog,
            ],
            'ap129_architecture_operations_mcp_tool' => [
                'valid' => $architectureOperationsMcpTool === [],
                'violations' => $architectureOperationsMcpTool,
            ],
            'ap130_architecture_operations_direct_surfaces' => [
                'valid' => $architectureOperationsDirectSurfaces === [],
                'violations' => $architectureOperationsDirectSurfaces,
            ],
            'ap131_self_improvement_architecture_operations_review' => [
                'valid' => $selfImprovementArchitectureOperationsReview === [],
                'violations' => $selfImprovementArchitectureOperationsReview,
            ],
            'ap132_architecture_operations_metadata_contract' => [
                'valid' => $architectureOperationsMetadataContract === [],
                'violations' => $architectureOperationsMetadataContract,
            ],
            'ap133_architecture_operations_filter_contract' => [
                'valid' => $architectureOperationsFilterContract === [],
                'violations' => $architectureOperationsFilterContract,
            ],
            'ap173_session_bootstrap_docs_split_plan_contract' => [
                'valid' => $sessionBootstrapDocsSplitPlanContract === [],
                'violations' => $sessionBootstrapDocsSplitPlanContract,
            ],
            'ap174_session_bootstrap_architecture_operations_contract' => [
                'valid' => $sessionBootstrapArchitectureOperationsContract === [],
                'violations' => $sessionBootstrapArchitectureOperationsContract,
            ],
            'ap175_feature_placement_architecture_operations_contract' => [
                'valid' => $featurePlacementArchitectureOperationsContract === [],
                'violations' => $featurePlacementArchitectureOperationsContract,
            ],
            'ap176_architecture_readiness_snapshot' => [
                'valid' => $architectureReadinessSnapshot === [],
                'violations' => $architectureReadinessSnapshot,
            ],
            'ap177_architecture_readiness_mcp_tool' => [
                'valid' => $architectureReadinessMcpTool === [],
                'violations' => $architectureReadinessMcpTool,
            ],
            'ap200_ap_agent_workflow_contracts' => [
                'valid' => $apAgentWorkflowContracts === [],
                'violations' => $apAgentWorkflowContracts,
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureOperationsFilterContract(): array
    {
        $violations = [];
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $commandPath = app_path('Console/Commands/AtlasAiArchitectureOperationsCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiArchitectureOperationsController.php');
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-133-architecture-operations-filter-contract.md');

        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'public function summary(array $filters = []): array',
            'private function normalizeFilters(array $filters): array',
            'private function matchesFilters(array $command, array $filters): bool',
            "'filters' => \$filters",
            "'id'",
            "'kind'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-133 catalog must support canonical id/kind filters [{$token}]";
            }
        }

        foreach ([
            '{--id= : Filter by stable operation id}',
            '{--kind= : Filter by operation kind}',
            '$catalog->summary($this->filters())',
            'private function filters(): array',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiArchitectureOperationsCommand.php: AP-133 CLI must expose id/kind filters [{$token}]";
            }
        }

        foreach ([
            "'id' => ['nullable', 'string', 'max:120']",
            "'kind' => ['nullable', 'string', 'max:120']",
            '$catalog->summary($data)',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiArchitectureOperationsController.php: AP-133 API must expose id/kind filters [{$token}]";
            }
        }

        foreach ([
            "'id' => ['type' => 'string'",
            "'kind' => ['type' => 'string'",
            "'atlas_architecture_operations' => \$this->toolResponse(\$id, \$this->architectureOperations(\$arguments))",
            "\$this->architectureOperations->summary(\$this->onlyScalarFilters(\$arguments, ['id', 'kind']))",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-133 MCP must expose id/kind filters [{$token}]";
            }
        }

        foreach ([
            'test_catalog_filters_architecture_operations_by_id_and_kind',
            "summary(['id' => 'provider_performance_report'])",
            "summary(['kind' => 'evidence_report'])",
            "'filters'",
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-133 catalog filters must be unit tested [{$token}]";
            }
        }

        foreach ([
            'test_command_filters_architecture_operations_by_id_and_kind',
            "'--kind' => 'evidence_report'",
            "'--id' => 'inbox_action_report'",
            'architecture_operations.filters',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php: AP-133 CLI filters must be covered [{$token}]";
            }
        }

        foreach ([
            'test_api_filters_architecture_operations_catalog',
            '/ai/architecture/operations?kind=evidence_report',
            '/ai/architecture/operations?id=provider_performance_report',
            'architecture_operations.filters',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php: AP-133 API filters must be covered [{$token}]";
            }
        }

        foreach ([
            'test_architecture_operations_tool_filters_shared_operations_catalog',
            "'arguments' => ['kind' => 'evidence_report']",
            'architecture_operations.filters',
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-133 MCP filters must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-133',
            'Architecture Operations Filter Contract',
            'id/kind',
            'ap133_architecture_operations_filter_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-133 architecture operations filter contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-133-architecture-operations-filter-contract.md: AP-133 contract doc must exist [{$token}]";
            }
        }

        return $violations;
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $sessionDoc = File::exists($sessionDocPath) ? File::get($sessionDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $operations',
            "'architecture_operations' => \$this->sessionOperations()",
            'private function sessionOperations(): array',
            "'architecture_readiness'",
            "'session_bootstrap'",
            "'feature_placement'",
            "'documentation_split_plan'",
            "'architecture_validate'",
            "'provider_projection_status'",
            "'code_intelligence_index'",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php: AP-174 session bootstrap must include focused architecture_operations [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')",
            'architecture_operations.operation_ids',
            "'architecture_readiness'",
            "'architecture_validate'",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiGovernanceApiTest.php: AP-174 API bootstrap architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'architecture_operations.schema_version')",
            "data_get(\$payload, 'architecture_operations.operation_ids')",
            "'architecture_readiness'",
            "'provider_projection_status'",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php: AP-174 CLI bootstrap architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$bootstrap, 'architecture_operations.operation_ids')",
            "'architecture_readiness'",
            "'documentation_split_plan'",
            "'architecture_validate'",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-174 MCP bootstrap architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-174',
            'Session Bootstrap Architecture Operations Contract',
            'architecture_operations',
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $operations',
            "'architecture_operations' => \$this->placementOperations()",
            'private function placementOperations(): array',
            "'architecture_readiness'",
            "'feature_placement'",
            "'session_bootstrap'",
            "'documentation_split_plan'",
            "'architecture_validate'",
            "'code_intelligence_index'",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php: AP-175 feature placement must include focused architecture_operations [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')",
            'architecture_operations.operation_ids',
            "'architecture_readiness'",
            "'feature_placement'",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiGovernanceApiTest.php: AP-175 API feature placement architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'architecture_operations.schema_version')",
            "data_get(\$payload, 'architecture_operations.operation_ids')",
            "'architecture_readiness'",
            "'feature_placement'",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php: AP-175 CLI feature placement architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$placement, 'architecture_operations.operation_ids')",
            "'architecture_readiness'",
            "'feature_placement'",
            "'architecture_validate'",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-175 MCP feature placement architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-175',
            'Feature Placement Architecture Operations Contract',
            'architecture_operations',
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
    private function scanArchitectureReadinessSnapshot(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureReadinessService.php');
        $commandPath = app_path('Console/Commands/AtlasAiArchitectureReadinessCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiGovernanceController.php');
        $routesPath = base_path('routes/api.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureReadinessCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiGovernanceApiTest.php');
        $catalogTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-176-architecture-readiness-snapshot.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $catalogTest = File::exists($catalogTestPath) ? File::get($catalogTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasAiArchitectureValidationService $validation',
            'AtlasDocumentationSplitPlanService $splitPlan',
            'AtlasProviderProjectionService $projection',
            'AtlasArchitectureOperationsCatalog $operations',
            "'schema_version' => 'atlas.architecture_readiness.v1'",
            "'checks' => \$checks",
            "'docs_split_plan' => [",
            "'provider_projection' => [",
            "'architecture_operations' => \$this->readinessOperations()",
            "'review_signal' => [",
            'private function readinessOperations(): array',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureReadinessService.php: AP-176 readiness snapshot must aggregate existing governance authorities [{$token}]";
            }
        }

        foreach ([
            "protected \$signature = 'atlas:ai:architecture-readiness",
            'AtlasArchitectureReadinessService $readiness',
            "'workspace' => \$this->option('workspace')",
            "'owner' => \$this->option('owner')",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiArchitectureReadinessCommand.php: AP-176 CLI must expose readiness snapshot [{$token}]";
            }
        }

        foreach ([
            'public function architectureReadiness(Request $request, AtlasArchitectureReadinessService $readiness): JsonResponse',
            "'workspace' => ['nullable', 'string', 'max:500']",
            "'owner' => ['nullable', 'string', 'max:120']",
            '$readiness->snapshot($data)',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiGovernanceController.php: AP-176 API controller must expose readiness snapshot [{$token}]";
            }
        }

        if (! str_contains($routes, "Route::get('/ai/architecture/readiness', [AtlasAiGovernanceController::class, 'architectureReadiness'])")) {
            $violations[] = 'routes/api.php: AP-176 API route /ai/architecture/readiness must exist';
        }

        foreach ([
            "'id' => 'architecture_readiness'",
            "'command' => 'php artisan atlas:ai:architecture-readiness --json'",
            "'kind' => 'readiness'",
            "'api_endpoint' => '/ai/architecture/readiness'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-176 catalog must publish architecture_readiness [{$token}]";
            }
        }

        foreach ([
            'test_command_returns_architecture_readiness_snapshot_as_json',
            "data_get(\$payload, 'schema_version')",
            'architecture_readiness',
            'review_signal.required_next_commands',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureReadinessCommandTest.php: AP-176 command tests must cover readiness output [{$token}]";
            }
        }

        foreach ([
            'test_architecture_readiness_api_returns_governance_snapshot',
            '/ai/architecture/readiness?owner=kernel_architecture',
            "assertJsonPath('schema_version', 'atlas.architecture_readiness.v1')",
            "assertJsonPath('architecture_operations.commands.0.id', 'architecture_readiness')",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiGovernanceApiTest.php: AP-176 API tests must cover readiness output [{$token}]";
            }
        }

        foreach ([
            "'architecture_readiness'",
            "'php artisan atlas:ai:architecture-readiness --json'",
            "summary(['kind' => 'readiness'])",
        ] as $token) {
            if (! str_contains($catalogTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-176 catalog tests must cover readiness operation [{$token}]";
            }
        }

        foreach ([
            'AP-176',
            'Architecture Readiness Snapshot',
            'ap176_architecture_readiness_snapshot',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-176 readiness snapshot must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-176-architecture-readiness-snapshot.md: AP-176 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureReadinessMcpTool(): array
    {
        $violations = [];
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $catalogTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-177-architecture-readiness-mcp-tool.md');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $catalogTest = File::exists($catalogTestPath) ? File::get($catalogTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureReadinessService',
            'private readonly AtlasArchitectureReadinessService $architectureReadiness',
            "'name' => 'atlas_architecture_readiness'",
            "'title' => 'Atlas Architecture Readiness'",
            "'workspace' => ['type' => 'string'",
            "'owner' => ['type' => 'string'",
            "'atlas_architecture_readiness' => \$this->toolResponse(\$id, \$this->architectureReadiness(\$arguments))",
            'private function architectureReadiness(array $arguments): array',
            "'tool' => 'atlas_architecture_readiness'",
            "'architecture_readiness' => \$payload",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-177 MCP must expose architecture readiness as read-only tool [{$token}]";
            }
        }

        if (! str_contains($catalog, "'mcp_tool' => 'atlas_architecture_readiness'")) {
            $violations[] = 'app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-177 architecture_readiness operation must declare mcp_tool atlas_architecture_readiness';
        }

        foreach ([
            'test_architecture_readiness_tool_exposes_preimplementation_snapshot',
            "'atlas_architecture_readiness'",
            "'atlas.architecture_readiness.v1'",
            "'continue_implementation_with_session_bootstrap_and_feature_placement'",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-177 MCP tests must lock readiness output [{$token}]";
            }
        }

        if (! str_contains($catalogTest, "'atlas_architecture_readiness'")) {
            $violations[] = 'tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-177 catalog test must lock architecture_readiness mcp_tool metadata';
        }

        foreach ([
            'AP-177',
            'Architecture Readiness MCP Tool',
            'ap177_architecture_readiness_mcp_tool',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-177 MCP readiness tool must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-177-architecture-readiness-mcp-tool.md: AP-177 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureOperationsMetadataContract(): array
    {
        $violations = [];
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-132-architecture-operations-metadata-contract.md');

        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "'schema_version' => 'atlas.architecture_operations.v1'",
            "'operation_ids' => array_values",
            "'id' => 'architecture_operations'",
            "'surface' => 'cli'",
            "'kind' => 'catalog'",
            "'output' => 'json'",
            "'kind' => 'evidence_report'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-132 architecture operations must expose stable machine-readable metadata [{$token}]";
            }
        }

        foreach ([
            "assertSame('atlas.architecture_operations.v1'",
            "'architecture_operations'",
            "'kernel_slo_report'",
            'commands.0.id',
            'commands.0.kind',
            'commands.0.surface',
            'commands.10.kind',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-132 catalog metadata must be unit tested [{$token}]";
            }
        }

        foreach ([
            'architecture_operations.schema_version',
            'architecture_operations.operation_ids',
            'architecture_operations.commands.0.id',
            'architecture_operations.commands.0.kind',
            'architecture_operations.commands.0.surface',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php: AP-132 CLI metadata contract must be covered [{$token}]";
            }
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php: AP-132 API metadata contract must be covered [{$token}]";
            }
        }

        foreach ([
            'architecture_operations.schema_version',
            'architecture_operations.operation_ids',
            'architecture_operations.commands.0.id',
            'architecture_operations.commands.0.kind',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-132 Observability metadata contract must be covered [{$token}]";
            }
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-132 MCP metadata contract must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-132',
            'Architecture Operations Metadata Contract',
            'atlas.architecture_operations.v1',
            'operation_ids',
            'ap132_architecture_operations_metadata_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-132 architecture operations metadata contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-132-architecture-operations-metadata-contract.md: AP-132 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementArchitectureOperationsReview(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-131-self-improvement-architecture-operations-review.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $architectureOperations',
            '...$this->architectureOperationsFindings($filters)',
            'private function architectureOperationsFindings(array $filters = []): array',
            'atlas.self_improvement.architecture_operations.v1',
            'restore_architecture_operations_catalog',
            'missing_architecture_operation',
            'atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'atlas ai self-improve --flow=provider_performance_review --hours=168 --json',
            'atlas ai self-improve --flow=provider_release_review --hours=168 --json',
            'atlas ai telemetry cost-rates --missing --hours=168 --json',
            'atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-131 Self-Improvement must review Architecture Operations catalog drift [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_architecture_operations_catalog_drift',
            'AtlasArchitectureOperationsCatalog(commandsOverride:',
            'atlas.self_improvement.architecture_operations.v1',
            'restore_architecture_operations_catalog',
            'missing_architecture_operation',
            'atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'atlas ai self-improve --flow=provider_performance_review --hours=168 --json',
            'atlas ai self-improve --flow=provider_release_review --hours=168 --json',
            'atlas ai telemetry cost-rates --missing --hours=168 --json',
            'atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-131 Self-Improvement architecture operations review must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-131',
            'Self-Improvement Architecture Operations Review',
            'architectureOperationsFindings',
            'restore_architecture_operations_catalog',
            'ap131_self_improvement_architecture_operations_review',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-131 Self-Improvement architecture operations review must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-131-self-improvement-architecture-operations-review.md: AP-131 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureOperationsDirectSurfaces(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasAiArchitectureOperationsCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiArchitectureOperationsController.php');
        $governanceControllerPath = app_path('Http/Controllers/AtlasAiGovernanceController.php');
        $routesPath = base_path('routes/api.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-130-architecture-operations-direct-surfaces.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $governanceController = File::exists($governanceControllerPath) ? File::get($governanceControllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "protected \$signature = 'atlas:ai:architecture-operations",
            'AtlasArchitectureOperationsCatalog $catalog',
            "'architecture_operations' => \$catalog->summary(\$this->filters())",
            'Atlas AI Architecture Operations',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiArchitectureOperationsCommand.php: AP-130 architecture operations CLI surface must consume shared catalog [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiArchitectureOperationsController extends Controller',
            'AtlasArchitectureOperationsCatalog $catalog',
            "'architecture_operations' => \$catalog->summary(\$data)",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiArchitectureOperationsController.php: AP-130 architecture operations API surface must consume shared catalog [{$token}]";
            }
        }

        foreach ([
            'AtlasAiArchitectureOperationsController',
            "Route::get('/ai/architecture/operations', AtlasAiArchitectureOperationsController::class)",
            'AtlasAiGovernanceController',
            "Route::get('/ai/session-bootstrap', [AtlasAiGovernanceController::class, 'sessionBootstrap'])",
            "Route::get('/ai/feature-placement', [AtlasAiGovernanceController::class, 'placeFeature'])",
            "Route::get('/ai/docs-split-plan', [AtlasAiGovernanceController::class, 'docsSplitPlan'])",
            "'strict' => ['nullable', 'boolean']",
            "'owner' => ['nullable', 'string', 'max:120']",
            "'severity' => ['nullable', 'string', 'max:120']",
            "'status' => ['nullable', 'string', 'max:120']",
            'AtlasGovernanceGateService $gate',
            '$gate->httpStatus($payload',
        ] as $token) {
            if (! str_contains($routes.$controller.$governanceController, $token)) {
                $violations[] = "routes/api.php: AP-130 architecture operations API route must exist [{$token}]";
            }
        }

        foreach ([
            'test_command_exposes_architecture_operations_as_json',
            'test_command_human_output_lists_architecture_operations',
            'atlas:ai:architecture-operations',
            'architecture_operations.command_count',
            'atlas ai architecture-operations --json',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php: AP-130 architecture operations CLI must be covered [{$token}]";
            }
        }

        foreach ([
            'test_api_exposes_architecture_operations_catalog',
            'test_api_requires_atlas_token',
            '/ai/architecture/operations',
            'architecture_operations.command_count',
            'atlas ai architecture-operations --json',
            '/ai/session-bootstrap',
            '/ai/feature-placement',
            '/ai/docs-split-plan',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php: AP-130 architecture operations API must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-130',
            'Architecture Operations Direct Surfaces',
            'atlas:ai:architecture-operations',
            '/ai/architecture/operations',
            'ap130_architecture_operations_direct_surfaces',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-130 direct architecture operations surfaces must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-130-architecture-operations-direct-surfaces.md: AP-130 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureOperationsMcpTool(): array
    {
        $violations = [];
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $gatePath = app_path('Services/Ai/Kernel/Architecture/AtlasGovernanceGateService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-129-architecture-operations-mcp-tool.md');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $gate = File::exists($gatePath) ? File::get($gatePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $architectureOperations',
            'AtlasSessionBootstrapService $sessionBootstrap',
            'AtlasFeaturePlacementService $featurePlacement',
            'AtlasDocumentationSplitPlanService $documentationSplitPlan',
            "'name' => 'atlas_architecture_operations'",
            "'name' => 'atlas_session_bootstrap'",
            "'name' => 'atlas_feature_placement'",
            "'name' => 'atlas_docs_split_plan'",
            "'atlas_architecture_operations' => \$this->toolResponse(\$id, \$this->architectureOperations(\$arguments))",
            "'atlas_session_bootstrap' => \$this->toolResponse(\$id, \$this->sessionBootstrap(\$arguments))",
            "'atlas_feature_placement' => \$this->toolResponse(\$id, \$this->featurePlacement(\$arguments))",
            "'atlas_docs_split_plan' => \$this->toolResponse(\$id, \$this->docsSplitPlan(\$arguments))",
            'private function architectureOperations(array $arguments): array',
            'private function sessionBootstrap(array $arguments): array',
            'private function featurePlacement(array $arguments): array',
            'private function docsSplitPlan(array $arguments): array',
            "\$this->architectureOperations->summary(\$this->onlyScalarFilters(\$arguments, ['id', 'kind']))",
            "\$this->documentationSplitPlan->plan(\$this->onlyScalarFilters(\$arguments, ['owner', 'severity', 'status']))",
            "'owner' => ['type' => 'string'",
            "'severity' => ['type' => 'string'",
            "'status' => ['type' => 'string'",
            "'strict' => ['type' => 'boolean'",
            '$strictBlocked',
            'AtlasGovernanceGateService $governanceGate',
            '$this->governanceGate->strictBlocked',
            '$this->governanceGate->mcpError',
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-129 architecture operations must be exposed as read-only MCP tool [{$token}]";
            }
        }

        foreach ([
            'session_bootstrap_blocked_by_strict_gate',
            'feature_placement_blocked_by_strict_gate',
        ] as $token) {
            if (! str_contains($gate, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasGovernanceGateService.php: AP-129 strict MCP gate errors must stay centralized [{$token}]";
            }
        }

        foreach ([
            'test_architecture_operations_tool_exposes_shared_operations_catalog',
            'atlas_architecture_operations',
            'atlas_session_bootstrap',
            'atlas_feature_placement',
            'atlas_docs_split_plan',
            'architecture_operations.section',
            'architecture_operations.command_count',
            'agent_behavior_report',
            'test_governance_tools_expose_session_bootstrap_feature_placement_and_split_plan',
            'test_session_bootstrap_tool_strict_mode_reports_blocked_gate',
            'test_feature_placement_tool_requires_feature',
            'test_feature_placement_tool_strict_mode_reports_blocked_gate',
            "'arguments' => ['status' => 'split_required']",
            "data_get(\$splitPlan, 'filters.status')",
            'session_bootstrap_blocked_by_strict_gate',
            'feature_placement_blocked_by_strict_gate',
            'atlas ai agent-behavior-report --hours=24 --json',
            'provider_release_review',
            'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json',
            'atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'atlas ai self-improve --flow=provider_performance_review --hours=168 --json',
            'atlas ai self-improve --flow=provider_release_review --hours=168 --json',
            'atlas ai telemetry cost-rates --missing --hours=168 --json',
            'atlas ai inbox-action-report --hours=24 --json',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-129 MCP architecture operations tool must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-129',
            'Architecture Operations MCP Tool',
            'atlas_architecture_operations',
            'AtlasArchitectureOperationsCatalog',
            'ap129_architecture_operations_mcp_tool',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-129 architecture operations MCP tool must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-129-architecture-operations-mcp-tool.md: AP-129 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureOperationsSharedCatalog(): array
    {
        $violations = [];
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $helpPath = app_path('Console/Commands/AtlasCliHelpCommand.php');
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-128-architecture-operations-shared-catalog.md');

        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $help = File::exists($helpPath) ? File::get($helpPath) : '';
        $observability = File::exists($observabilityPath) ? File::get($observabilityPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'final class AtlasArchitectureOperationsCatalog',
            "return 'arquitetura_mae'",
            'public function commands(): array',
            'public function summary(array $filters = []): array',
            "'command_count' => count(\$commands)",
            "'atlas ai architecture-operations --json'",
            "'atlas ai architecture-validate'",
            "'atlas engineering knowledge docs-health --json'",
            "'php artisan atlas:ai:session-bootstrap --task=\"<task>\" --json'",
            "'php artisan atlas:ai:place-feature \"<feature>\" --json'",
            "'php artisan atlas:ai:docs-split-plan --json'",
            "'focused_command' => 'php artisan atlas:ai:docs-split-plan --owner=<owner_area> --json'",
            "'filter_options' => ['owner', 'severity', 'status']",
            "'mcp_tool' => 'atlas_docs_split_plan'",
            "'api_endpoint' => '/ai/session-bootstrap'",
            "'api_endpoint' => '/ai/feature-placement'",
            "'api_endpoint' => '/ai/docs-split-plan'",
            "'atlas engineering knowledge sync --prune --json'",
            "'atlas engineering knowledge index-code --prune --json'",
            "'atlas ai agent-behavior-report --hours=24 --json'",
            "'atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json'",
            "'atlas ai self-improve --flow=provider_performance_review --hours=168 --json'",
            "'php artisan atlas:ai:provider-release-review --provider=<provider> --title=\"<release>\" --json'",
            "'atlas ai self-improve --flow=provider_release_review --hours=168 --json'",
            "'atlas ai telemetry cost-rates --missing --hours=168 --json'",
            "'atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json'",
            "'atlas ai inbox-action-report --hours=24 --json'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-128 shared architecture operations catalog must exist [{$token}]";
            }
        }

        foreach ([
            'AtlasArchitectureOperationsCatalog $architectureOperations',
            '$architectureOperations->sectionKey() => $architectureOperations->commands()',
        ] as $token) {
            if (! str_contains($help, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliHelpCommand.php: AP-128 CLI help must consume shared architecture operations catalog [{$token}]";
            }
        }

        foreach ([
            'AtlasArchitectureOperationsCatalog $architectureOperations',
            "'architecture_operations' => \$architectureOperations->summary()",
        ] as $token) {
            if (! str_contains($observability, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: AP-128 Observability must expose shared architecture operations catalog [{$token}]";
            }
        }

        foreach ([
            'test_catalog_exposes_canonical_architecture_operations',
            'AtlasArchitectureOperationsCatalog',
            "'arquitetura_mae'",
            'atlas ai architecture-operations --json',
            'atlas engineering knowledge docs-health --json',
            'php artisan atlas:ai:session-bootstrap --task="<task>" --json',
            'php artisan atlas:ai:place-feature "<feature>" --json',
            'php artisan atlas:ai:docs-split-plan --json',
            'php artisan atlas:ai:docs-split-plan --owner=<owner_area> --json',
            "data_get(\$commandsById, 'documentation_split_plan.filter_options')",
            "data_get(\$commandsById, 'documentation_split_plan.mcp_tool')",
            '/ai/session-bootstrap',
            '/ai/feature-placement',
            '/ai/docs-split-plan',
            'atlas engineering knowledge sync --prune --json',
            'atlas engineering knowledge index-code --prune --json',
            'atlas ai agent-behavior-report --hours=24 --json',
            'atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'atlas ai self-improve --flow=provider_performance_review --hours=168 --json',
            'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json',
            'atlas ai self-improve --flow=provider_release_review --hours=168 --json',
            'atlas ai telemetry cost-rates --missing --hours=168 --json',
            'atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json',
            'atlas ai inbox-action-report --hours=24 --json',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-128 catalog must be unit tested [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_includes_architecture_operations_catalog',
            "assertJsonPath('architecture_operations.section', 'arquitetura_mae')",
            "\$this->assertSame(count(\$commands), \$response->json('architecture_operations.command_count'))",
            'atlas ai architecture-operations --json',
            'atlas engineering knowledge docs-health --json',
            'php artisan atlas:ai:session-bootstrap --task="<task>" --json',
            'php artisan atlas:ai:place-feature "<feature>" --json',
            'php artisan atlas:ai:docs-split-plan --json',
            '/ai/session-bootstrap',
            '/ai/feature-placement',
            '/ai/docs-split-plan',
            'atlas engineering knowledge sync --prune --json',
            'atlas engineering knowledge index-code --prune --json',
            'atlas ai agent-behavior-report --hours=24 --json',
            'atlas ai self-improvement-schedule-report --hours=24 --json',
            'atlas ai self-improve --flow=provider_performance_review --hours=168 --json',
            'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json',
            'atlas ai self-improve --flow=provider_release_review --hours=168 --json',
            'atlas ledger replay --envelope=<id> --json',
            'atlas ai telemetry cost-rates --missing --hours=168 --json',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-128 Observability architecture operations catalog must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-128',
            'Architecture Operations Shared Catalog',
            'AtlasArchitectureOperationsCatalog',
            'architecture_operations',
            'ap128_architecture_operations_shared_catalog',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-128 shared architecture operations catalog must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-128-architecture-operations-shared-catalog.md: AP-128 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliHelpArchitectureOperationsDiscovery(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasCliHelpCommand.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $testPath = base_path('tests/Feature/AtlasCliHelpCommandTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-127-cli-help-architecture-operations-discovery.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $architectureOperations',
            '$architectureOperations->sectionKey() => $architectureOperations->commands()',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliHelpCommand.php: AP-127 CLI help must expose architecture operations discovery [{$token}]";
            }
        }

        foreach ([
            "return 'arquitetura_mae'",
            "'command' => 'atlas ai architecture-operations --json'",
            "'command' => 'atlas ai architecture-validate'",
            "'command' => 'atlas ai slo --hours=24 --json'",
            "'command' => 'atlas ai kernel-pipeline-report --hours=24 --json'",
            "'command' => 'atlas ai repair-report --hours=24 --json'",
            "'command' => 'atlas ai provider-performance --hours=24 --json'",
            "'command' => 'php artisan atlas:ai:provider-release-review --provider=<provider> --title=\"<release>\" --json'",
            "'command' => 'atlas ai agent-behavior-report --hours=24 --json'",
            "'command' => 'atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json'",
            "'command' => 'atlas ai self-improve --flow=provider_performance_review --hours=168 --json'",
            "'command' => 'atlas ai self-improve --flow=provider_release_review --hours=168 --json'",
            "'command' => 'atlas ai self-improvement-schedule-report --hours=24 --json'",
            "'command' => 'atlas ai inbox-action-report --hours=24 --json'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-127 architecture operation commands must remain discoverable [{$token}]";
            }
        }

        foreach ([
            'arquitetura_mae',
            'atlas ai architecture-operations --json',
            'atlas ai architecture-validate',
            'atlas ai slo --hours=24 --json',
            'atlas ai kernel-pipeline-report --hours=24 --json',
            'atlas ai repair-report --hours=24 --json',
            'atlas ai provider-performance --hours=24 --json',
            'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json',
            'atlas ai agent-behavior-report --hours=24 --json',
            'atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'atlas ai self-improve --flow=provider_performance_review --hours=168 --json',
            'atlas ai self-improve --flow=provider_release_review --hours=168 --json',
            'atlas ai self-improvement-schedule-report --hours=24 --json',
            'atlas ai inbox-action-report --hours=24 --json',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/AtlasCliHelpCommandTest.php: AP-127 CLI help architecture operations must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-127',
            'CLI Help Architecture Operations Discovery',
            'arquitetura_mae',
            'atlas ai architecture-operations --json',
            'atlas ai agent-behavior-report --hours=24 --json',
            'atlas ai inbox-action-report --hours=24 --json',
            'ap127_cli_help_architecture_operations_discovery',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-127 CLI help architecture operations discovery must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-127-cli-help-architecture-operations-discovery.md: AP-127 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureValidatePostAp98HumanOutput(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasAiArchitectureValidateCommand.php');
        $testPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-126-architecture-validate-post-ap98-human-output.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'renderPostAp98StaticScanViolations($payload)',
            'private function renderPostAp98StaticScanViolations(array $payload): void',
            "data_get(\$payload, 'kernel.static_scan', [])",
            "preg_match('/^ap(?P<number>\\d+)_/', \$key, \$matches)",
            "(int) \$matches['number'] <= 98",
            '$this->error("[kernel.static.{$key}] ".$violation)',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiArchitectureValidateCommand.php: AP-126 human output must render post-AP98 static scan violations generically [{$token}]";
            }
        }

        foreach ([
            'test_human_output_renders_post_ap98_static_scan_violations',
            'ap125_inbox_action_report_surfaces',
            '[kernel.static.ap125_inbox_action_report_surfaces]',
            'AP-125 synthetic violation for human output',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: AP-126 human output regression must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-126',
            'Architecture Validate Post-AP98 Human Output',
            'renderPostAp98StaticScanViolations',
            'ap126_architecture_validate_post_ap98_human_output',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-126 architecture validate human output must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-126-architecture-validate-post-ap98-human-output.md: AP-126 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanInboxActionReportSurfaces(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasAiInboxActionReportCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiInboxActionReportController.php');
        $routesPath = base_path('routes/api.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiInboxActionReportApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-125-inbox-action-report-surfaces.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'atlas:ai:inbox-action-report',
            'KernelReplayReportInput $input',
            'inboxActionReportForWindow(now()->subHours($hours), filters: $filters)',
            "'inbox_actions' => \$report",
            "'actor_type' => ['actor-type']",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiInboxActionReportCommand.php: AP-125 command must expose Inbox action replay via shared input contract [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiInboxActionReportController',
            'KernelReplayReportInput $input',
            "'recommended_action' => ['nullable', 'string', 'max:180']",
            '$replay->inboxActionReportForWindow(now()->subHours($hours), filters: $filters)',
            "'inbox_actions' => \$report",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiInboxActionReportController.php: AP-125 API must expose Inbox action replay via shared input contract [{$token}]";
            }
        }

        foreach ([
            'AtlasAiInboxActionReportController',
            "Route::get('/ai/inbox-actions/report', AtlasAiInboxActionReportController::class)",
        ] as $token) {
            if (! str_contains($routes, $token)) {
                $violations[] = "routes/api.php: AP-125 Inbox action report API route must be registered [{$token}]";
            }
        }

        foreach ([
            'test_command_summarizes_inbox_action_window_as_json',
            'test_command_filters_inbox_action_report_as_json',
            'test_command_reports_unavailable_when_ledger_table_is_missing',
            'LedgerEventType::InboxActionRecorded',
            'atlas:ai:inbox-action-report',
            'open_reviewable_inbox_action_evidence_proposal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php: AP-125 command surface must be covered [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_report_api_returns_window_summary',
            'test_inbox_action_report_api_filters_window_summary',
            'test_inbox_action_report_api_requires_atlas_token',
            '/ai/inbox-actions/report',
            'LedgerEventType::InboxActionRecorded',
            'wait_for_inbox_action_evidence',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiInboxActionReportApiTest.php: AP-125 API surface must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-125',
            'Inbox Action Report Surfaces',
            'atlas:ai:inbox-action-report',
            '/ai/inbox-actions/report',
            'inboxActionReportForWindow',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-125 Inbox action report surfaces must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-125-inbox-action-report-surfaces.md: AP-125 contract doc must exist [{$token}]";
            }
        }

        return $violations;
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
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
    private function scanSelfImprovementInboxActionReplayReview(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-123-self-improvement-inbox-action-replay-review.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'inboxActionReplayFindings(',
            'inboxActionReportForWindow(',
            'normalizedInboxActionFilters(',
            'atlas.self_improvement.inbox_action_replay_gap.v1',
            'review_patch_action_without_diff_refs',
            'record_rivals_review_action_without_scores',
            'open_reviewable_inbox_action_evidence_proposal',
            'self-improvement:inbox-action-replay:',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-123 Self-Improvement must consume Inbox action replay gaps [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_inbox_action_replay_patch_review_gap',
            'test_self_improvement_detects_rivals_review_action_without_scores',
            'recordInboxActionEvent(',
            'LedgerEventType::InboxActionRecorded',
            'atlas.self_improvement.inbox_action_replay_gap.v1',
            'record_rivals_review_action_without_scores',
            'open_reviewable_inbox_action_evidence_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-123 Inbox action replay review must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-123',
            'Self-Improvement Inbox Action Replay Review',
            'inboxActionReplayFindings',
            'atlas.self_improvement.inbox_action_replay_gap.v1',
            'review_patch_action_without_diff_refs',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-123 Inbox action replay review must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-123-self-improvement-inbox-action-replay-review.md: AP-123 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanInboxActionMcpReport(): array
    {
        $violations = [];
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-122-inbox-action-mcp-report.md');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "'name' => 'atlas_inbox_action_report'",
            "'atlas_inbox_action_report' => \$this->toolResponse(\$id, \$this->inboxActionReport(\$arguments))",
            'private function inboxActionReport(array $arguments): array',
            '$this->ledgerReplay->inboxActionReportForWindow(',
            "'inbox_actions' => \$report",
            "'action'",
            "'actor_type'",
            "'inbox_item_category'",
            "'recommended_action'",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-122 Inbox action replay must be exposed as read-only MCP report [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_report_tool_exposes_replay_read_model',
            'recordInboxActionForMcp(',
            'atlas_inbox_action_report',
            'inbox_actions.review_signal.status',
            'wait_for_inbox_action_evidence',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-122 MCP Inbox action report must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-122',
            'Inbox Action MCP Report',
            'atlas_inbox_action_report',
            'inboxActionReportForWindow',
            'wait_for_inbox_action_evidence',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-122 Inbox action MCP report must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-122-inbox-action-mcp-report.md: AP-122 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanInboxActionReplayReadModel(): array
    {
        $violations = [];
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-121-inbox-action-replay-read-model.md');

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'public function inboxActionReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array',
            'LedgerEventType::InboxActionRecorded',
            'inboxActionEventFromEvent(',
            'inboxActionSummary(',
            'inboxActionReviewSignal(',
            'normalizedInboxActionFilters(',
            'matchesInboxActionFilters(',
            "'open_reviewable_inbox_action_evidence_proposal'",
            "'wait_for_inbox_action_evidence'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-121 Inbox action events must be projectable through replay [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_window_report_projects_human_review_evidence',
            'test_inbox_action_window_report_filters_and_warns_when_patch_review_lacks_diff_refs',
            'recordInboxActionEvent(',
            'LedgerEventType::InboxActionRecorded',
            'inboxActionReportForWindow(',
            'review_patch_action_without_diff_refs',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-121 Inbox action replay read model must be tested [{$token}]";
            }
        }

        foreach ([
            'AP-121',
            'Inbox Action Replay Read Model',
            'inboxActionReportForWindow',
            'LedgerEventType::InboxActionRecorded',
            'review_patch_action_without_diff_refs',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-121 Inbox action replay read model must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-121-inbox-action-replay-read-model.md: AP-121 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanInboxActionEvidenceLedgerContract(): array
    {
        $violations = [];
        $eventTypePath = app_path('Services/Ai/Kernel/Evidence/LedgerEventType.php');
        $actionsPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $testPath = base_path('tests/Feature/MobileGatewayTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-120-inbox-action-evidence-ledger-contract.md');

        $eventType = File::exists($eventTypePath) ? File::get($eventTypePath) : '';
        $actions = File::exists($actionsPath) ? File::get($actionsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        if (! str_contains($eventType, "case InboxActionRecorded = 'INBOX_ACTION_RECORDED'")) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/LedgerEventType.php: AP-120 must define INBOX_ACTION_RECORDED';
        }

        foreach ([
            'private readonly AtlasEvidenceLedger $ledger',
            'private function recordInboxActionLedgerEvent',
            'LedgerEventType::InboxActionRecorded',
            "'schema_version' => 'atlas.inbox_action.v1'",
            "'recommended_action' => \$this->string(data_get(\$item->payload ?? [], 'proposal_contract.review_signal.recommended_action'))",
            "'emitter_stage' => 'atlas.inbox'",
        ] as $token) {
            if (! str_contains($actions, $token)) {
                $violations[] = "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-120 Inbox actions must be recorded in Evidence Ledger [{$token}]";
            }
        }

        foreach ([
            'LedgerEventType::InboxActionRecorded',
            'atlas.inbox_action.v1',
            "data_get(\$ledgerEvent->payload, 'action')",
            "data_get(\$ledgerEvent->payload, 'result.payload.diff_refs.0.path')",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/MobileGatewayTest.php: AP-120 Inbox action ledger event must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-120',
            'Inbox Action Evidence Ledger Contract',
            'LedgerEventType::InboxActionRecorded',
            'atlas.inbox_action.v1',
            'review_patch',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-120 Inbox action ledger contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-120-inbox-action-evidence-ledger-contract.md: AP-120 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliInboxReviewActionResultParity(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasCliInboxCommand.php');
        $testPath = base_path('tests/Feature/MobileGatewayTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-119-cli-inbox-review-action-result-parity-contract.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "return \$this->printItem(\$result['item'], \$result['result'] ?? [])",
            "'result' => \$result",
            'private function printItem(AiInboxItem $item, array $result = [])',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliInboxCommand.php: AP-119 CLI respond JSON must preserve action result parity [{$token}]";
            }
        }

        foreach ([
            'Review patch via CLI',
            "'--action' => 'review_patch'",
            'result.payload.recommended_action',
            'result.payload.diff_refs.0.path',
            'review_cli_proposal_contract',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/MobileGatewayTest.php: AP-119 CLI review_patch parity must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-119',
            'CLI Inbox Review Action Result Parity',
            'atlas:cli:inbox respond',
            'review_patch',
            'recommended_action',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-119 CLI review action parity must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-119-cli-inbox-review-action-result-parity-contract.md: AP-119 contract doc must exist [{$token}]";
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
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
    private function scanSelfImprovementScheduleReplayInboxGapEmission(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-116-self-improvement-schedule-replay-inbox-gap-emission-contract.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            '$item = $this->proposals->emit([',
            "'emitted_to_inbox' => \$emittedInboxItemId !== null",
            "'emitted_inbox_item_id' => \$emittedInboxItemId",
            "'emitted_inbox_item_ids' => array_values(\$emitted)",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-116 Self-Improvement must emit inbox-gap findings through the standard proposal/ledger path [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_emits_schedule_replay_missing_inbox_ref_proposal',
            'ProposalInboxEmitter::class',
            'emitted_to_inbox',
            'emitted_inbox_item_id',
            'emitted_inbox_item_ids',
            'restore_or_reemit_missing_self_improvement_inbox_items',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-116 inbox-gap proposal emission must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-116',
            'Self-Improvement Schedule Replay Inbox Gap Emission',
            'emitted_to_inbox',
            'OPERATION_COMPLETED',
            'restore_or_reemit_missing_self_improvement_inbox_items',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-116 inbox-gap emission must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-116-self-improvement-schedule-replay-inbox-gap-emission-contract.md: AP-116 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayInboxGapFinding(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-115-self-improvement-schedule-replay-inbox-gap-finding-contract.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "\$missingInboxItemIds = array_values((array) (\$report['emitted_inbox_item_missing_ids'] ?? []))",
            "'title' => 'Restaurar propostas do Inbox emitidas pelo Self-Improvement'",
            "'schema_version' => 'atlas.self_improvement.schedule_replay_inbox_gap.v1'",
            "'recommended_action' => 'restore_or_reemit_missing_self_improvement_inbox_items'",
            "'dedupe_key' => 'self-improvement:schedule-replay-inbox-gap:'.sha1",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-115 Self-Improvement must turn schedule replay inbox hydration gaps into reviewable findings [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_schedule_replay_missing_inbox_refs',
            'recordSelfImprovementCompletion',
            'atlas.self_improvement.schedule_replay_inbox_gap.v1',
            'restore_or_reemit_missing_self_improvement_inbox_items',
            'self-improvement:schedule-replay-inbox-gap:',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-115 missing inbox refs finding must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-115',
            'Self-Improvement Schedule Replay Inbox Gap Finding',
            'atlas.self_improvement.schedule_replay_inbox_gap.v1',
            'restore_or_reemit_missing_self_improvement_inbox_items',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-115 inbox gap finding must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-115-self-improvement-schedule-replay-inbox-gap-finding-contract.md: AP-115 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayInboxHydrationGapSignal(): array
    {
        $violations = [];
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandPath = app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-114-schedule-replay-inbox-hydration-gap-signal-contract.md');

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "'emitted_inbox_item_hydration_available' => \$hydrationAvailable",
            "'emitted_inbox_item_missing_ids' => \$missingIds",
            "'emitted_inbox_item_hydration_available' => Schema::hasTable('ai_inbox_items')",
            "'emitted_inbox_item_missing_ids' => \$missingInboxItemIds",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-114 replay must expose inbox hydration availability and missing ids [{$token}]";
            }
        }

        foreach ([
            'Inbox hydration',
            'Missing inbox refs',
            "'missing refs'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php: AP-114 CLI must show inbox hydration gaps [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_schedule_window_report_exposes_missing_inbox_refs',
            'emitted_inbox_item_hydration_available',
            'emitted_inbox_item_missing_ids',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-114 missing inbox refs must be unit tested [{$token}]";
            }
        }

        foreach ([
            'missing_inbox_refs',
            'emitted_inbox_item_hydration_available',
            'emitted_inbox_item_missing_ids',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: AP-114 CLI/API JSON gap signals must be covered [{$token}]";
            }
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: AP-114 API gap signals must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-114',
            'Schedule Replay Inbox Hydration Gap Signal',
            'emitted_inbox_item_hydration_available',
            'emitted_inbox_item_missing_ids',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-114 hydration gap signal must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-114-schedule-replay-inbox-hydration-gap-signal-contract.md: AP-114 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayInboxItemHydrationSurfaceParity(): array
    {
        $violations = [];
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-113-schedule-replay-inbox-item-hydration-surface-parity-contract.md');

        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AiInboxItem::unguarded',
            'self_improvement_schedule_replay.emitted_inbox_items.0.title',
            'self_improvement_schedule_replay.emitted_inbox_items.0.review_signal.recommended_action',
            'self_improvement_schedule_replay.recent_events.0.emitted_inbox_items.0.title',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-113 Observability must expose hydrated schedule replay inbox items [{$token}]";
            }
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-113 MCP must expose hydrated schedule replay inbox items [{$token}]";
            }
        }

        foreach ([
            'AP-113',
            'Schedule Replay Inbox Item Hydration Surface Parity',
            'Observability',
            'MCP',
            'emitted_inbox_items',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-113 hydration surface parity must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-113-schedule-replay-inbox-item-hydration-surface-parity-contract.md: AP-113 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayInboxItemHydration(): array
    {
        $violations = [];
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandPath = app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-112-schedule-replay-inbox-item-hydration-contract.md');

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'use App\\Models\\AiInboxItem;',
            'private function selfImprovementInboxItemsById(array $ids): array',
            'private function selfImprovementInboxItemSummary(AiInboxItem $item): array',
            'private function withSelfImprovementInboxItems(array $event, array $inboxItemsById, bool $hydrationAvailable): array',
            "'emitted_inbox_items' => \$emittedInboxItems",
            "'review_signal' => data_get(\$item->payload, 'proposal_contract.review_signal')",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-112 schedule replay must hydrate emitted inbox item summaries [{$token}]";
            }
        }

        foreach ([
            'Emitted inbox items',
            'compactInboxItems(',
            "'inbox items'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php: AP-112 CLI human output must expose hydrated inbox item summaries [{$token}]";
            }
        }

        foreach ([
            'AiInboxItem::unguarded',
            'emitted_inbox_items.0.title',
            'review_schedule_repair',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-112 replay hydration must be unit tested [{$token}]";
            }
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: AP-112 CLI hydration must be covered [{$token}]";
            }
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: AP-112 API hydration must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-112',
            'Schedule Replay Inbox Item Hydration',
            'emitted_inbox_items',
            'review_signal',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-112 hydration contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-112-schedule-replay-inbox-item-hydration-contract.md: AP-112 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayInboxRefsSurfaceParity(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-111-schedule-replay-inbox-refs-surface-parity-contract.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'Completed runs',
            'Emitted proposals',
            'Emitted inbox refs',
            "'inbox refs'",
            'compactList(',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php: AP-111 CLI human output must expose schedule replay completion refs [{$token}]";
            }
        }

        foreach ([
            'self_improvement_schedule_replay.completed_count',
            'self_improvement_schedule_replay.emitted_count',
            'self_improvement_schedule_replay.emitted_inbox_item_ids',
            'Emitted inbox refs',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: AP-111 CLI surface parity must be covered [{$token}]";
            }
        }

        foreach ([
            'self_improvement_schedule_replay.completed_count',
            'self_improvement_schedule_replay.emitted_count',
            'self_improvement_schedule_replay.emitted_inbox_item_ids.0',
            'self_improvement_schedule_replay.recent_events.0.emitted_inbox_item_ids.0',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: AP-111 API surface parity must be covered [{$token}]";
            }
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-111 Observability surface parity must be covered [{$token}]";
            }
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-111 MCP surface parity must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-111',
            'Schedule Replay Inbox Refs Surface Parity',
            'Completed runs',
            'Emitted inbox refs',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-111 surface parity contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-111-schedule-replay-inbox-refs-surface-parity-contract.md: AP-111 contract doc must exist and define surface parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayInboxRefsContract(): array
    {
        $violations = [];
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'private function selfImprovementCompletionByEnvelope(CarbonInterface $since, CarbonInterface $until): array',
            'private function withSelfImprovementCompletion(array $event, ?array $completion): array',
            "'emitted_inbox_item_ids' => array_values((array) (\$completion['emitted_inbox_item_ids'] ?? []))",
            "'completed_count' => \$events->where('completed', true)->count()",
            "'emitted_inbox_item_ids' => \$emittedInboxItemIds",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-110 schedule replay must expose emitted inbox refs [{$token}]";
            }
        }

        foreach ([
            'recordSelfImprovementCompletionEvent',
            'emitted_inbox_item_ids',
            'completed_count',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-110 schedule replay inbox refs must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-110',
            'Schedule Replay Inbox Refs',
            'selfImprovementScheduleReportForWindow',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-110 schedule replay inbox refs contract must be documented [{$token}]";
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
    private function scanProviderUsagePerformanceContract(): array
    {
        $violations = [];
        $payloadPath = app_path('Services/Ai/Kernel/Evidence/ProviderUsagePayload.php');
        $projectionPath = app_path('Services/Ai/Kernel/Evidence/ProviderPerformanceProjection.php');
        $workerPath = app_path('Services/Ai/AiWorker.php');
        $strategyPath = app_path('Services/Ai/Cli/AtlasCliProviderStrategyService.php');
        $dynamicComputeMarketPath = app_path('Services/Ai/Kernel/Decision/DynamicComputeMarketAdvisor.php');
        $dynamicComputeMarketReportPath = app_path('Services/Ai/Kernel/Decision/DynamicComputeMarketReportService.php');
        $dynamicComputeMarketCommandPath = app_path('Console/Commands/AtlasAiDynamicComputeMarketCommand.php');
        $dynamicComputeMarketApiPath = app_path('Http/Controllers/AtlasAiDynamicComputeMarketController.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $inboxActionsPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $costRateServicePath = app_path('Services/Ai/Telemetry/AiProviderCostRateService.php');
        $costRateCommandPath = app_path('Console/Commands/AiTelemetryCostRatesCommand.php');
        $replayServicePath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandPath = app_path('Console/Commands/AtlasAiProviderPerformanceCommand.php');
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $apiPath = app_path('Http/Controllers/AtlasAiProviderPerformanceController.php');
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $routesPath = base_path('routes/api.php');
        $bootstrapPath = base_path('bootstrap/app.php');
        $projectionTestPath = base_path('tests/Unit/Ai/ProviderPerformanceProjectionTest.php');
        $workerTestPath = base_path('tests/Feature/Ai/AiWorkerProviderChoiceTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiProviderPerformanceCommandTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiProviderPerformanceApiTest.php');
        $dynamicComputeMarketCommandTestPath = base_path('tests/Feature/Ai/AtlasAiDynamicComputeMarketCommandTest.php');
        $dynamicComputeMarketApiTestPath = base_path('tests/Feature/Ai/AtlasAiDynamicComputeMarketApiTest.php');
        $selfImprovementRuntimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $decideReceiptTestPath = base_path('tests/Unit/Ai/AtlasDecideReceiptIntegrationTest.php');
        $telemetryMetricsTestPath = base_path('tests/Feature/AiTelemetryMetricsTest.php');
        $telemetryDiagnosticsTestPath = base_path('tests/Feature/AiTelemetryToolDiagnosticsTest.php');
        $telemetryDocPath = base_path('docs/atlas-ai-telemetry.md');
        $modelSelectionDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md');
        $dynamicComputeMarketApPath = base_path('docs/ap/AP-147-dynamic-compute-market-shadow-surface.md');

        $payload = File::exists($payloadPath) ? File::get($payloadPath) : '';
        $projection = File::exists($projectionPath) ? File::get($projectionPath) : '';
        $worker = File::exists($workerPath) ? File::get($workerPath) : '';
        $strategy = File::exists($strategyPath) ? File::get($strategyPath) : '';
        $dynamicComputeMarket = File::exists($dynamicComputeMarketPath) ? File::get($dynamicComputeMarketPath) : '';
        $dynamicComputeMarketReport = File::exists($dynamicComputeMarketReportPath) ? File::get($dynamicComputeMarketReportPath) : '';
        $dynamicComputeMarketCommand = File::exists($dynamicComputeMarketCommandPath) ? File::get($dynamicComputeMarketCommandPath) : '';
        $dynamicComputeMarketApi = File::exists($dynamicComputeMarketApiPath) ? File::get($dynamicComputeMarketApiPath) : '';
        $selfImprovement = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        $inboxActions = File::exists($inboxActionsPath) ? File::get($inboxActionsPath) : '';
        $costRateService = File::exists($costRateServicePath) ? File::get($costRateServicePath) : '';
        $costRateCommand = File::exists($costRateCommandPath) ? File::get($costRateCommandPath) : '';
        $replayService = File::exists($replayServicePath) ? File::get($replayServicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $api = File::exists($apiPath) ? File::get($apiPath) : '';
        $observability = File::exists($observabilityPath) ? File::get($observabilityPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $bootstrap = File::exists($bootstrapPath) ? File::get($bootstrapPath) : '';
        $projectionTest = File::exists($projectionTestPath) ? File::get($projectionTestPath) : '';
        $workerTest = File::exists($workerTestPath) ? File::get($workerTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $dynamicComputeMarketCommandTest = File::exists($dynamicComputeMarketCommandTestPath) ? File::get($dynamicComputeMarketCommandTestPath) : '';
        $dynamicComputeMarketApiTest = File::exists($dynamicComputeMarketApiTestPath) ? File::get($dynamicComputeMarketApiTestPath) : '';
        $selfImprovementRuntimeTest = File::exists($selfImprovementRuntimeTestPath) ? File::get($selfImprovementRuntimeTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $decideReceiptTest = File::exists($decideReceiptTestPath) ? File::get($decideReceiptTestPath) : '';
        $telemetryMetricsTest = File::exists($telemetryMetricsTestPath) ? File::get($telemetryMetricsTestPath) : '';
        $telemetryDiagnosticsTest = File::exists($telemetryDiagnosticsTestPath) ? File::get($telemetryDiagnosticsTestPath) : '';
        $telemetryDoc = File::exists($telemetryDocPath) ? File::get($telemetryDocPath) : '';
        $modelSelectionDoc = File::exists($modelSelectionDocPath) ? File::get($modelSelectionDocPath) : '';
        $dynamicComputeMarketAp = File::exists($dynamicComputeMarketApPath) ? File::get($dynamicComputeMarketApPath) : '';

        foreach ([
            'class ProviderUsagePayload',
            "public const SCHEMA_VERSION = 'atlas.provider_usage.v1'",
            'public function called(AiJob $job, AiJobAttempt $attempt',
            'public function returned(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result',
            'public function fallback(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result',
            "'provider_cli'",
            "'domain'",
            "'flow'",
            "'task_type'",
            "'specialist_profile'",
            "'risk'",
            "'router_decision_id'",
            "'selection_mode'",
            "'total_tokens'",
            "'cost_microusd'",
            "'cost_confidence'",
            "'cost_mode'",
        ] as $token) {
            if (! str_contains($payload, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/ProviderUsagePayload.php: provider usage payload must keep AP-99 normalized field [{$token}]";
            }
        }

        foreach ([
            'class ProviderPerformanceProjection',
            'public function reportForWindow(CarbonInterface $since',
            'LedgerEventType::ProviderReturned',
            'LedgerEventType::ProviderFallback',
            "'provider_cli'",
            "'domain'",
            "'task_type'",
            "'specialist_profile'",
            "'success_rate'",
            "'average_latency_seconds'",
            "'average_cost_microusd'",
            "'cost_confidence_counts'",
            "'groups'",
        ] as $token) {
            if (! str_contains($projection, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/ProviderPerformanceProjection.php: provider performance projection must aggregate AP-99 ledger events [{$token}]";
            }
        }

        foreach ([
            'ProviderUsagePayload $providerUsage',
            'LedgerEventType::ProviderCalled',
            'LedgerEventType::ProviderReturned',
            'LedgerEventType::ProviderFallback',
            '$this->providerUsage->called(',
            '$this->providerUsage->returned(',
            '$this->providerUsage->fallback(',
        ] as $token) {
            if (! str_contains($worker, $token)) {
                $violations[] = "app/Services/Ai/AiWorker.php: worker must emit normalized provider usage events for AP-99 [{$token}]";
            }
        }

        foreach ([
            'ProviderPerformanceProjection $performance',
            "'empirical_performance'",
            '$this->performance->reportForWindow(',
        ] as $token) {
            if (! str_contains($strategy, $token)) {
                $violations[] = "app/Services/Ai/Cli/AtlasCliProviderStrategyService.php: Strategy Matrix must expose AP-99 empirical performance projection [{$token}]";
            }
        }

        foreach ([
            'class DynamicComputeMarketAdvisor',
            'ProviderPerformanceProjection $providerPerformance',
            "'mode' => 'shadow_advisory'",
            "'authority' => 'advisory_only_atlas_decide_remains_authority'",
            "'routing_control'",
            "'changes_provider' => false",
            "'quality_basis'",
            "'latency_basis'",
            "'cost_basis'",
            "'missing_cost_status'",
            "'sample_size'",
            "'recommended_next_action'",
            "'recommendation_reason'",
            "'benchmark_candidate'",
            'run_controlled_provider_benchmark_before_policy_change',
        ] as $token) {
            if (! str_contains($dynamicComputeMarket, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/DynamicComputeMarketAdvisor.php: Dynamic Compute Market must stay advisory, explainable, and non-routing [{$token}]";
            }
        }

        foreach ([
            'class DynamicComputeMarketReportService',
            'DynamicComputeMarketAdvisor $advisor',
            "'schema_version' => 'atlas.dynamic_compute_market_report.v1'",
            "'mode' => 'report_only'",
            "'authority' => 'read_only_no_routing_change'",
            "'dynamic_compute_market' => \$market",
            'private function requiredScalar',
        ] as $token) {
            if (! str_contains($dynamicComputeMarketReport, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/DynamicComputeMarketReportService.php: Dynamic Compute Market report service must stay read-only and advisor-backed [{$token}]";
            }
        }

        foreach ([
            "protected \$signature = 'atlas:ai:dynamic-compute-market",
            'DynamicComputeMarketReportService $reports',
            "'status' => 'invalid_input'",
            'Atlas Dynamic Compute Market',
            'Changes provider',
        ] as $token) {
            if (! str_contains($dynamicComputeMarketCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiDynamicComputeMarketCommand.php: Dynamic Compute Market CLI must expose governed read-only advice [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiDynamicComputeMarketController',
            'DynamicComputeMarketReportService $reports',
            "'provider' => ['required', 'string', 'max:120']",
            '$reports->report($data)',
            "=== 'ok' ? 200 : 503",
        ] as $token) {
            if (! str_contains($dynamicComputeMarketApi, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiDynamicComputeMarketController.php: Dynamic Compute Market API must expose authenticated read-only report contract [{$token}]";
            }
        }

        foreach ([
            'ProviderPerformanceProjection $providerPerformance',
            'DynamicComputeMarketAdvisor $dynamicComputeMarket',
            'providerPerformanceFindings(',
            'dynamicComputeMarketFindings(',
            'atlas.self_improvement.dynamic_compute_market.v1',
            'Benchmark revisavel do Dynamic Compute Market',
            'run_controlled_provider_benchmark_before_policy_change',
            'self-improvement:provider-performance:',
            'self-improvement:provider-cost-rates:',
            'self-improvement:dynamic-compute-market:',
            'configure_provider_cost_rates',
            'atlas.provider_usage.v1',
        ] as $token) {
            if (! str_contains($selfImprovement, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: provider_performance_review must consume AP-99 projection [{$token}]";
            }
        }

        foreach ([
            "'configure_provider_cost_rates' => \$this->configureProviderCostRates(\$locked, \$input)",
            'private readonly AiProviderCostRateService $providerCostRates',
            'private function configureProviderCostRates(AiInboxItem $item, array $input): array',
            "'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1'",
            '$this->providerCostRates->upsert($rateTemplate)',
        ] as $token) {
            if (! str_contains($inboxActions, $token)) {
                $violations[] = "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-99 provider cost-rate Inbox action must close unknown-cost findings [{$token}]";
            }
        }

        foreach ([
            'private function requiredString',
            'private function nonNegativeInt',
            'private function currency',
            'effective_until must not be before effective_from.',
            '{$field} must be greater than or equal to 0.',
            'currency must be a 3 to 8 character code.',
        ] as $token) {
            if (! str_contains($costRateService, $token)) {
                $violations[] = "app/Services/Ai/Telemetry/AiProviderCostRateService.php: AP-99 cost rates must reject invalid provider/model/rate windows before contaminating AP-99 [{$token}]";
            }
        }

        foreach ([
            'private function renderError',
            "'ok' => false",
            'Invalid cost rate input:',
            "'currency'",
            "'input uUSD/1K'",
            "'output uUSD/1K'",
        ] as $token) {
            if (! str_contains($costRateCommand, $token)) {
                $violations[] = "app/Console/Commands/AiTelemetryCostRatesCommand.php: AP-99 cost-rate CLI must report governed validation failures [{$token}]";
            }
        }

        foreach ([
            'provider_cost_rate_action_count',
            'provider_cost_rate_applied_count',
            'provider_cost_rate_provider_counts',
            'configure_provider_cost_rates_action_without_applied_rate',
            'provider_cost_rates_configured',
        ] as $token) {
            if (! str_contains($replayService, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-99 provider cost-rate Inbox action must be projected in replay reports [{$token}]";
            }
        }

        foreach ([
            "protected \$signature = 'atlas:ai:provider-performance",
            'ProviderPerformanceProjection $performance',
            '$performance->reportForWindow(',
            "'provider_performance'",
            '{--provider=',
            '{--specialist-profile=',
            "data_get(\$report, 'review_signal.status'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiProviderPerformanceCommand.php: AP-99 must expose provider performance through CLI read model [{$token}]";
            }
        }

        foreach ([
            'AtlasAiProviderPerformanceCommand::class',
            'AtlasAiDynamicComputeMarketCommand::class',
        ] as $token) {
            if (! str_contains($bootstrap, $token)) {
                $violations[] = "bootstrap/app.php: AP-99 provider performance CLI command must be registered [{$token}]";
            }
        }

        foreach ([
            'ProviderPerformanceProjection $providerPerformance',
            "'name' => 'atlas_provider_performance_report'",
            "'atlas_provider_performance_report' => \$this->toolResponse(\$id, \$this->providerPerformanceReport(\$arguments))",
            'providerPerformanceReport(array $arguments)',
            '$this->providerPerformance->reportForWindow(',
            "'name' => 'atlas_dynamic_compute_market_report'",
            "'atlas_dynamic_compute_market_report' => \$this->toolResponse(\$id, \$this->dynamicComputeMarketReport(\$arguments))",
            'dynamicComputeMarketReport(array $arguments)',
            'DynamicComputeMarketReportService $dynamicComputeMarketReports',
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-99 provider performance must be available as a read-only MCP report [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiProviderPerformanceController',
            'ProviderPerformanceProjection $performance',
            'KernelReplayReportInput $input',
            '$performance->reportForWindow(',
            "'provider_performance'",
            "'ledger_unavailable'",
        ] as $token) {
            if (! str_contains($api, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiProviderPerformanceController.php: AP-99 provider performance API must expose the shared read model [{$token}]";
            }
        }

        foreach ([
            'AtlasAiProviderPerformanceController::class',
            "'/ai/provider-performance'",
            'AtlasAiDynamicComputeMarketController::class',
            "'/ai/dynamic-compute-market'",
        ] as $token) {
            if (! str_contains($routes, $token)) {
                $violations[] = "routes/api.php: AP-99 provider performance API route must be registered [{$token}]";
            }
        }

        foreach ([
            'ProviderPerformanceProjection $providerPerformance',
            '$providerPerformance->reportForWindow($since)',
            "'provider_performance' => \$providerPerformanceReport",
        ] as $token) {
            if (! str_contains($observability, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: AP-99 provider performance must appear in Observability through the shared projection [{$token}]";
            }
        }

        foreach ([
            'ProviderPerformanceProjectionTest',
            'provider_performance_projection_groups',
            'ProviderUsagePayload::SCHEMA_VERSION',
        ] as $token) {
            if (! str_contains($projectionTest, $token)) {
                $violations[] = "tests/Unit/Ai/ProviderPerformanceProjectionTest.php: AP-99 projection contract must have focused tests [{$token}]";
            }
        }

        foreach ([
            'atlas.provider_usage.v1',
            "'router_fallback_provider'",
            "'selection_mode'",
        ] as $token) {
            if (! str_contains($workerTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiWorkerProviderChoiceTest.php: AP-99 worker hot path must assert normalized provider usage payload [{$token}]";
            }
        }

        foreach ([
            'AtlasAiProviderPerformanceCommandTest',
            'atlas:ai:provider-performance',
            'provider_performance.event_count',
            'provider_performance.average_cost_microusd',
            'provider_performance.cost_confidence_counts',
            'Review signal',
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiProviderPerformanceCommandTest.php: AP-99 provider performance CLI must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas_provider_performance_report',
            'test_provider_performance_report_summarizes_normalized_provider_usage',
            'provider_performance.success_rate',
            "'provider_cli' => 'codex_cli'",
            'test_inbox_action_report_tool_exposes_provider_cost_rate_actions',
            'provider_cost_rate_action_count',
            'atlas_dynamic_compute_market_report',
            'test_dynamic_compute_market_report_exposes_read_only_shadow_advice',
            'test_dynamic_compute_market_report_preserves_review_signal_when_ledger_is_unavailable',
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-99 provider performance MCP report must be covered [{$token}]";
            }
        }

        foreach ([
            'AtlasAiProviderPerformanceApiTest',
            '/ai/provider-performance?hours=24&provider=codex_cli&domain=programming',
            'provider_performance.review_signal.status',
            'provider_performance.average_cost_microusd',
            'provider_performance.cost_confidence_counts.estimated',
            'wait_for_provider_usage_evidence',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiProviderPerformanceApiTest.php: AP-99 provider performance API must be covered [{$token}]";
            }
        }

        foreach ([
            'AtlasAiDynamicComputeMarketCommandTest',
            'atlas:ai:dynamic-compute-market',
            'atlas.dynamic_compute_market_report.v1',
            'read_only_no_routing_change',
            'dynamic_compute_market.routing_control.changes_provider',
            'provider is required.',
            'test_command_reports_unavailable_without_ap99_ledger_projection',
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($dynamicComputeMarketCommandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiDynamicComputeMarketCommandTest.php: Dynamic Compute Market CLI surface must be covered [{$token}]";
            }
        }

        foreach ([
            'AtlasAiDynamicComputeMarketApiTest',
            '/ai/dynamic-compute-market?provider=codex_cli',
            'atlas.dynamic_compute_market_report.v1',
            'read_only_no_routing_change',
            'dynamic_compute_market.routing_control.changes_provider',
            'test_api_requires_atlas_token',
            'test_api_reports_unavailable_without_ap99_ledger_projection',
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($dynamicComputeMarketApiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiDynamicComputeMarketApiTest.php: Dynamic Compute Market API surface must be covered [{$token}]";
            }
        }

        foreach ([
            'test_provider_performance_review_emits_dynamic_compute_market_benchmark_proposal',
            'test_self_improvement_command_surfaces_dynamic_compute_market_proposal_without_routing_change',
            'atlas.self_improvement.dynamic_compute_market.v1',
            'routing_control.changes_provider',
            'routing_control.routing_authority',
        ] as $token) {
            if (! str_contains($selfImprovementRuntimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-147 Curator proposal-only contract must be covered [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_includes_provider_performance_summary',
            'ProviderUsagePayload::SCHEMA_VERSION',
            'provider_performance.provider_counts.codex_cli',
            'provider_performance.review_signal.status',
            'test_observability_payload_exposes_provider_cost_rate_inbox_actions',
            'inbox_actions.provider_cost_rate_action_count',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-99 provider performance Observability payload must be covered [{$token}]";
            }
        }

        foreach ([
            'test_cost_rate_upsert_rejects_negative_input_and_output_rates',
            'test_cost_rate_upsert_rejects_empty_provider_and_model',
            'test_cost_rate_upsert_rejects_effective_until_before_effective_from',
            'test_cost_rate_command_reports_invalid_input_as_json_and_human_error',
            'test_cost_rate_import_reports_indexed_validation_errors',
        ] as $token) {
            if (! str_contains($telemetryMetricsTest, $token)) {
                $violations[] = "tests/Feature/AiTelemetryMetricsTest.php: AP-99 cost-rate governance must be covered [{$token}]";
            }
        }

        foreach ([
            'test_cost_rate_service_rejects_invalid_effective_window',
            'effective_until must not be before effective_from.',
        ] as $token) {
            if (! str_contains($telemetryDiagnosticsTest, $token)) {
                $violations[] = "tests/Feature/AiTelemetryToolDiagnosticsTest.php: AP-99 cost-rate diagnostics must cover invalid windows [{$token}]";
            }
        }

        foreach ([
            'Cost rates sao governados',
            'provider/model obrigatorios',
            'micro-USD por 1K tokens',
            'effective_until',
        ] as $token) {
            if (! str_contains($telemetryDoc, $token)) {
                $violations[] = "docs/atlas-ai-telemetry.md: AP-99 cost-rate governance must be documented [{$token}]";
            }
        }

        foreach ([
            'test_dynamic_compute_market_uses_ap99_provider_performance_inside_decision_receipt',
            'test_dynamic_compute_market_requests_cost_rates_when_quality_is_ok_but_cost_is_unknown',
            'test_dynamic_compute_market_recommends_benchmark_when_better_alternative_has_insufficient_sample',
            'test_dynamic_compute_market_keeps_selected_provider_when_evidence_is_stable',
            'test_dynamic_compute_market_prefers_sufficient_sample_benchmark_candidate',
            'test_dynamic_compute_market_prefers_higher_quality_when_candidate_samples_are_sufficient',
            'recommended_next_action',
            'recommendation_reason',
            'explanation.quality_basis',
            'explanation.latency_basis',
            'explanation.cost_basis',
            'routing_control.changes_provider',
        ] as $token) {
            if (! str_contains($decideReceiptTest, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasDecideReceiptIntegrationTest.php: Dynamic Compute Market receipt explainability must be covered [{$token}]";
            }
        }

        foreach ([
            'DynamicComputeMarketAdvisor',
            'Dynamic Compute Market Report',
            'quality_basis',
            'latency_basis',
            'cost_basis',
            'recommended_next_action',
            'recommendation_reason',
            'benchmark controlado',
        ] as $token) {
            if (! str_contains($modelSelectionDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md: Dynamic Compute Market explainability must be documented [{$token}]";
            }
        }

        foreach ([
            'AP-147',
            'implemented-shadow-contract',
            'atlas.dynamic_compute_market_report.v1',
            'read_only_no_routing_change',
            'routing_control.changes_provider=false',
            'atlas_dynamic_compute_market_report',
            'AtlasAiDynamicComputeMarketCommandTest',
            'AtlasAiDynamicComputeMarketApiTest',
            'AtlasOpenBrainMcpServiceTest::test_dynamic_compute_market_report_exposes_read_only_shadow_advice',
            'AtlasOpenBrainMcpServiceTest::test_dynamic_compute_market_report_preserves_review_signal_when_ledger_is_unavailable',
            'AtlasSelfImprovementRuntimeTest::test_provider_performance_review_emits_dynamic_compute_market_benchmark_proposal',
            'AtlasSelfImprovementRuntimeTest::test_self_improvement_command_surfaces_dynamic_compute_market_proposal_without_routing_change',
        ] as $token) {
            if (! str_contains($dynamicComputeMarketAp, $token)) {
                $violations[] = "docs/ap/AP-147-dynamic-compute-market-shadow-surface.md: Dynamic Compute Market shadow surface contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @param  array<int,string>  $tokens
     * @param  array<int,string>  $ignoredPaths
     * @return array<int,string>
     */
    private function scanPhpFilesForForbiddenTokens(string $directory, array $tokens, array $ignoredPaths = []): array
    {
        if (! File::isDirectory($directory)) {
            return ["missing directory [{$directory}]"];
        }

        $violations = [];
        $ignored = array_flip(array_map(fn (string $path): string => realpath($path) ?: $path, $ignoredPaths));

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath() ?: $file->getPathname();
            if (isset($ignored[$path])) {
                continue;
            }

            $contents = File::get($path);
            foreach ($tokens as $token) {
                if (str_contains($contents, $token)) {
                    $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).": forbidden token [{$token}]";
                }
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureValidationObservability(): array
    {
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $observability = File::exists($observabilityPath) ? File::get($observabilityPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'AtlasAiArchitectureValidationService',
            'AtlasAiArchitectureValidationService $architectureValidation',
            '$kernelSlo = $ledgerReplay->sloReportForWindow($since)',
            '$kernelRepair = $ledgerReplay->repairReportForWindow($since)',
            '$kernelPipeline = $ledgerReplay->kernelPipelineReportForWindow($since)',
            '$architecturePayload = $architectureValidation->payload();',
            "'architecture_validation' => \$this->architectureValidationSummary(\$architecturePayload)",
            'private function architectureValidationSummary(array $payload): array',
            "'summary' => data_get(\$payload, 'kernel.static_scan.summary', [])",
        ] as $token) {
            if (! str_contains($observability, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: observability must expose compact architecture validation health without duplicating payload [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_includes_architecture_validation_summary',
            "assertJsonPath('architecture_validation.status', 'ok')",
            "assertJsonPath('architecture_validation.kernel.static_scan.summary.failed_count', 0)",
            "assertContains('ap37_architecture_validation_surface'",
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: observability test must lock architecture validation summary [{$token}]";
            }
        }

        foreach ([
            '`architecture_validation`',
            'compacto de arquitetura',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: canonical docs must describe architecture validation observability summary [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureValidationSurface(): array
    {
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php');
        $commandPath = app_path('Console/Commands/AtlasAiArchitectureValidateCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiArchitectureValidateController.php');
        $routesPath = base_path('routes/api.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'class AtlasAiArchitectureValidationService',
            'public function payload(): array',
            'private function staticScanPayload(',
            'private function staticScanSummary(array $staticScan): array',
            "'static_scan' => \$staticScanPayload",
            "'validated_at' => now()->toJSON()",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php: architecture validation payload must live in the shared kernel service [{$token}]";
            }
        }

        foreach ([
            'AtlasAiArchitectureValidationService',
            'public function handle(AtlasAiArchitectureValidationService $validation): int',
            '$payload = $validation->payload();',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiArchitectureValidateCommand.php: CLI must render the shared architecture validation payload [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiArchitectureValidateController',
            'public function __invoke(AtlasAiArchitectureValidationService $validation): JsonResponse',
            '$payload = $validation->payload();',
            "response()->json(\$payload, \$payload['status'] === 'ok' ? 200 : 503)",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiArchitectureValidateController.php: API must expose the shared architecture validation payload [{$token}]";
            }
        }

        if (! str_contains($routes, 'AtlasAiArchitectureValidateController') || ! str_contains($routes, "Route::get('/ai/architecture/validate', AtlasAiArchitectureValidateController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/architecture/validate must be registered inside the atlas.token API group';
        }

        foreach ([
            "data_get(\$payload, 'kernel.static_scan.summary.total_count')",
            "data_get(\$payload, 'kernel.static_scan.summary.violation_count')",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: CLI test must lock static scan summary payload [{$token}]";
            }
        }

        foreach ([
            "'/ai/architecture/validate'",
            "assertJsonPath('kernel.static_scan.summary.failed_count', 0)",
            "assertContains('ap36_kernel_pipeline_health_read_model'",
            'assertUnauthorized()',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php: API test must lock architecture validation route and auth [{$token}]";
            }
        }

        foreach ([
            '`GET /ai/architecture/validate`',
            '`kernel.static_scan.summary`',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: canonical docs must describe architecture validation API surface [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureValidationContractParity(): array
    {
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $operatingDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-operating-system.md');
        $violations = [];

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';
        $operatingDocs = File::exists($operatingDocsPath) ? File::get($operatingDocsPath) : '';

        foreach ([
            'public function payload(): array',
            'private function staticScanSummary(array $staticScan): array',
            "'schema_version' => 1",
            "'status' =>",
            "'validated_at' => now()->toJSON()",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php: shared payload service must remain the canonical contract source [{$token}]";
            }
        }

        foreach ([
            'test_architecture_validate_api_matches_shared_service_contract',
            'AtlasAiArchitectureValidationService::class',
            '$expected = $this->app->make(AtlasAiArchitectureValidationService::class)->payload();',
            "assertSame(data_get(\$expected, 'kernel.static_scan.summary.total_count'), \$response->json('kernel.static_scan.summary.total_count'))",
            "assertSame(data_get(\$expected, 'capabilities.count'), \$response->json('capabilities.count'))",
            "assertSame(data_get(\$expected, 'onboarding.domain_count'), \$response->json('onboarding.domain_count'))",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php: API must prove parity with the shared validation service [{$token}]";
            }
        }

        foreach ([
            'test_command_validates_architecture_contracts_as_json',
            "data_get(\$payload, 'kernel.static_scan.summary.total_count')",
            "data_get(\$payload, 'kernel.static_scan.summary.passed_count')",
            "assertContains('ap39_architecture_validation_contract_parity'",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: CLI must lock the same architecture validation contract summary [{$token}]";
            }
        }

        foreach ([
            'AP-39',
            'fonte unica',
            '`AtlasAiArchitectureValidationService`',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($operatingDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: canonical docs must describe architecture validation contract parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureValidationMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            'AtlasAiArchitectureValidationService',
            'private readonly AtlasAiArchitectureValidationService $architectureValidation',
            "'name' => 'atlas_architecture_validate'",
            "'title' => 'Atlas Architecture Validate'",
            "'atlas_architecture_validate' => \$this->toolResponse(\$id, \$this->architectureValidate(\$arguments))",
            'private function architectureValidate(array $arguments): array',
            '$payload = $this->architectureValidation->payload();',
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP must expose architecture validation via shared service [{$token}]";
            }
        }

        foreach ([
            'test_architecture_validate_tool_exposes_shared_contract_summary',
            'test_architecture_validate_tool_rejects_invalid_detail',
            "assertContains('atlas_architecture_validate'",
            "assertSame('atlas_architecture_validate', \$structured['tool'])",
            "assertContains(\n            'ap39_architecture_validation_contract_parity'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP architecture validation tool must be tested [{$token}]";
            }
        }

        foreach ([
            '`atlas_architecture_validate`',
            'Open Brain/MCP',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: MCP docs must describe architecture validation tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementArchitectureValidationReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            'AtlasAiArchitectureValidationService',
            'private readonly AtlasAiArchitectureValidationService $architectureValidation',
            '...$this->architectureValidationFindings($filters)',
            'private function architectureValidationFindings(array $filters = []): array',
            '$payload = $this->architectureValidation->payload();',
            "'type' => 'architecture_validation_ap'",
            "'dedupe_key' => 'self-improvement:architecture-validation:'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must review architecture validation from the shared service [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_architecture_validation_regressions_from_shared_service',
            'AtlasAiArchitectureValidationService::class',
            "'ap40_architecture_validation_mcp_tool'",
            "'self-improvement:architecture-validation:'",
            "data_get(\$finding, 'metadata.failed_keys')",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Self-Improvement architecture validation finding must be tested [{$token}]";
            }
        }

        foreach ([
            'architecture validation',
            'AP41',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement architecture validation review [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementArchitectureAuditSchedule(): array
    {
        $configPath = config_path('atlas_ai.php');
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $unitTestPath = base_path('tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php');
        $featureTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $config = File::exists($configPath) ? File::get($configPath) : '';
        $schedule = File::exists($schedulePath) ? File::get($schedulePath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        if (! str_contains($config, 'nightly_review,weekly_architecture_audit,repair_loop_review,kernel_pipeline_review,agent_behavior_review')) {
            $violations[] = 'config/atlas_ai.php: ATLAS_AI_SELF_IMPROVEMENT_FLOWS default must include weekly_architecture_audit between nightly and repair reviews';
        }

        foreach ([
            "'nightly_review',\n            'weekly_architecture_audit',\n            'repair_loop_review',\n            'kernel_pipeline_review',\n            'agent_behavior_review'",
            'Restore the default nightly_review, weekly_architecture_audit, repair_loop_review, kernel_pipeline_review, and agent_behavior_review schedule.',
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: default schedule must include architecture audit [{$token}]";
            }
        }

        foreach ([
            'test_default_schedule_runs_nightly_architecture_repair_and_kernel_pipeline_reviews',
            "'weekly_architecture_audit'",
            'registered_command_count',
            'atlas:ai:self-improve --flow=weekly_architecture_audit --hours=24 --limit=5 --json',
        ] as $token) {
            if (! str_contains($unitTest, $token) && ! str_contains($featureTest, $token) && ! str_contains($observabilityTest, $token)) {
                $violations[] = "tests: schedule tests must lock weekly_architecture_audit in recurring self-improvement plan [{$token}]";
            }
        }

        foreach ([
            'weekly_architecture_audit',
            'AP42',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe scheduled architecture audit [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementFlowCadenceContract(): array
    {
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $bootstrapPath = base_path('bootstrap/app.php');
        $unitTestPath = base_path('tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php');
        $featureTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $schedule = File::exists($schedulePath) ? File::get($schedulePath) : '';
        $bootstrap = File::exists($bootstrapPath) ? File::get($bootstrapPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            "'cadence' => \$this->cadenceForFlow(\$flow)",
            "'week_day' => \$this->weekDayForFlow(\$flow)",
            'private function cadenceForFlow(string $flow): string',
            "return \$flow === 'weekly_architecture_audit' ? 'weekly' : 'daily';",
            'private function weekDayForFlow(string $flow): ?int',
            "'cadence_counts' => \$this->cadenceCounts(\$commands)",
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: flow cadence contract must be explicit [{$token}]";
            }
        }

        foreach ([
            "\$scheduledEvent = \$schedule->command(\$selfImprovementCommand['command']);",
            "(\$selfImprovementCommand['cadence'] ?? 'daily') === 'weekly'",
            "->weeklyOn((int) (\$selfImprovementCommand['week_day'] ?? 1), \$selfImprovementCommand['time'])",
            "->dailyAt(\$selfImprovementCommand['time'])",
        ] as $token) {
            if (! str_contains($bootstrap, $token)) {
                $violations[] = "bootstrap/app.php: scheduler must respect per-flow cadence from Self-Improvement schedule contract [{$token}]";
            }
        }

        foreach ([
            'test_scheduled_commands_mark_weekly_architecture_audit_as_weekly',
            "\$this->assertSame('weekly', \$commands[0]['cadence'])",
            "\$this->assertSame(1, \$commands[0]['week_day'])",
            "assertJsonPath('cadence_counts.weekly', 1)",
            "assertJsonPath('self_improvement_schedule.cadence_counts.weekly', 1)",
        ] as $token) {
            if (! str_contains($unitTest, $token) && ! str_contains($featureTest, $token) && ! str_contains($observabilityTest, $token)) {
                $violations[] = "tests: Self-Improvement cadence contract must be locked by unit/API/observability tests [{$token}]";
            }
        }

        foreach ([
            'cadence',
            'weeklyOn',
            'AP43',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement per-flow cadence [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementCommandNextRunContract(): array
    {
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $unitTestPath = base_path('tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $schedule = File::exists($schedulePath) ? File::get($schedulePath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            "'next_run_at' => \$this->nextRunAtForCommand(",
            "'next_run_at' => \$command['next_run_at']",
            'private function nextRunAtForCommand(string $time, string $timezone, string $cadence, ?int $weekDay): ?string',
            'while ((int) $next->dayOfWeek !== $targetWeekDay || $next->lessThanOrEqualTo($now))',
            'private function hashableCommands(array $commands): array',
            "unset(\$command['next_run_at']);",
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: command next_run_at contract must be explicit and hash-stable [{$token}]";
            }
        }

        foreach ([
            "\$this->assertSame('2026-05-11T05:00:00.000000Z', \$commands[1]['next_run_at'])",
            "\$this->assertNotSame(\$first['commands'][0]['next_run_at'], \$second['commands'][0]['next_run_at'])",
            "\$this->assertSame(\$first['plan_hash'], \$second['plan_hash'])",
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php: command next_run_at must be covered, including weekly cadence and stable plan hash [{$token}]";
            }
        }

        foreach ([
            'per-command `next_run_at`',
            'AP44',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement per-command next_run_at [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            'AtlasSelfImprovementScheduleService',
            "'name' => 'atlas_self_improvement_schedule'",
            "'atlas_self_improvement_schedule' => \$this->toolResponse(\$id, \$this->selfImprovementSchedule(\$arguments))",
            'private function selfImprovementSchedule(array $arguments): array',
            "'allowed_detail' => ['health', 'plan', 'commands']",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: Open Brain must expose Self-Improvement schedule as read-only MCP tool [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_schedule_tool_exposes_recurring_health',
            'test_self_improvement_schedule_tool_rejects_invalid_detail',
            "\$this->assertContains('atlas_self_improvement_schedule'",
            "\$this->assertSame('weekly_architecture_audit', data_get(\$structured, 'schedule.commands.1.flow'))",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP schedule tool must be covered in inventory, success and invalid-detail tests [{$token}]";
            }
        }

        foreach ([
            'atlas_self_improvement_schedule',
            'AP45',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule MCP tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleHealthReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            'AtlasSelfImprovementScheduleService $schedule',
            '...$this->selfImprovementScheduleFindings($filters)',
            'private function selfImprovementScheduleFindings(array $filters = []): array',
            '$health = $this->schedule->scheduleHealth();',
            "'title' => 'Corrigir schedule recorrente do Self-Improvement'",
            "'dedupe_key' => 'self-improvement:schedule-health:'",
            "'type' => 'self_improvement_schedule'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Curator must review its recurring schedule through AtlasSelfImprovementScheduleService [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_unhealthy_recurring_schedule',
            "'self-improvement:schedule-health:'.sha1('warning:registered:invalid_self_improvement_flows_configured')",
            "\$this->assertSame('Corrigir schedule recorrente do Self-Improvement'",
            "\$this->assertSame(['invalid_self_improvement_flows_configured'], data_get(\$finding, 'metadata.issues'))",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Self-Improvement schedule health review must be covered [{$token}]";
            }
        }

        foreach ([
            'schedule health',
            'AP46',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule health review [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleHealthLedgerEvent(): array
    {
        $eventTypePath = app_path('Services/Ai/Kernel/Evidence/LedgerEventType.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $eventType = File::exists($eventTypePath) ? File::get($eventTypePath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        if (! str_contains($eventType, "case SelfImprovementScheduleObserved = 'SELF_IMPROVEMENT_SCHEDULE_OBSERVED';")) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/LedgerEventType.php: missing SELF_IMPROVEMENT_SCHEDULE_OBSERVED ledger event type';
        }

        foreach ([
            'LedgerEventType::SelfImprovementScheduleObserved',
            "'schedule_health' => \$this->scheduleHealthLedgerProjection(\$this->schedule->scheduleHealth())",
            'private function scheduleHealthLedgerProjection(array $health): array',
            "'health_status' => data_get(\$health, 'health.status')",
            "'scheduler_registration' => (array) (\$health['scheduler_registration'] ?? [])",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: schedule health must be recorded as a dedicated ledger event [{$token}]";
            }
        }

        foreach ([
            'LedgerEventType::SelfImprovementScheduleObserved->value',
            "\$this->assertArrayHasKey('health_status', data_get(\$scheduleEvent->payload, 'schedule_health'))",
            "\$this->assertArrayHasKey('scheduler_registration', data_get(\$scheduleEvent->payload, 'schedule_health'))",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: schedule observed ledger event must be asserted [{$token}]";
            }
        }

        foreach ([
            'SELF_IMPROVEMENT_SCHEDULE_OBSERVED',
            'AP47',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe schedule health ledger event [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayReadModel(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $observability = File::exists($observabilityPath) ? File::get($observabilityPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            'public function selfImprovementScheduleReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null): array',
            'LedgerEventType::SelfImprovementScheduleObserved->value',
            'private function selfImprovementScheduleEventFromEvent(array $event): array',
            'private function selfImprovementScheduleEventSummary(Collection $events): array',
            "'schedule_observation_count' => \$events->count()",
            "'review_required' => \$warningCount > 0",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: schedule observed events must have a replay read model [{$token}]";
            }
        }

        foreach ([
            '$selfImprovementScheduleReplay = $ledgerReplay->selfImprovementScheduleReportForWindow($since)',
            "'self_improvement_schedule_replay' => \$selfImprovementScheduleReplay",
        ] as $token) {
            if (! str_contains($observability, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: observability must expose schedule replay read model [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_schedule_window_report_projects_schedule_health_events',
            'recordSelfImprovementScheduleEvent',
            "\$this->assertSame(2, \$report['schedule_observation_count'])",
            "\$this->assertTrue(\$report['review_required'])",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: schedule replay read model must be covered [{$token}]";
            }
        }

        foreach ([
            'self_improvement_schedule_replay',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: observability schedule replay must be covered [{$token}]";
            }
        }

        foreach ([
            'schedule replay',
            'AP48',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule replay read model [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplaySurfaces(): array
    {
        $commandPath = app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiSelfImprovementScheduleReportController.php');
        $routesPath = base_path('routes/api.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            'atlas:ai:self-improvement-schedule-report',
            '$replay->selfImprovementScheduleReportForWindow(now()->subHours($hours))',
            "'self_improvement_schedule_replay' => \$report",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php: CLI report must expose schedule replay read model [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiSelfImprovementScheduleReportController extends Controller',
            '$replay->selfImprovementScheduleReportForWindow(now()->subHours($hours))',
            "'self_improvement_schedule_replay' => \$report",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiSelfImprovementScheduleReportController.php: API report must expose schedule replay read model [{$token}]";
            }
        }

        foreach ([
            'AtlasAiSelfImprovementScheduleReportController',
            "Route::get('/ai/self-improvement/schedule/report', AtlasAiSelfImprovementScheduleReportController::class)",
        ] as $token) {
            if (! str_contains($routes, $token)) {
                $violations[] = "routes/api.php: schedule replay report route must be registered [{$token}]";
            }
        }

        foreach ([
            'test_command_summarizes_self_improvement_schedule_replay_as_json',
            'test_command_reports_unavailable_when_ledger_table_is_missing',
            'atlas:ai:self-improvement-schedule-report',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: CLI schedule report must be covered [{$token}]";
            }
        }

        foreach ([
            'test_schedule_report_api_returns_window_summary',
            'test_schedule_report_api_requires_atlas_token',
            'test_schedule_report_api_returns_service_unavailable_when_ledger_table_is_missing',
            '/ai/self-improvement/schedule/report',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: API schedule report must be covered [{$token}]";
            }
        }

        foreach ([
            'self-improvement-schedule-report',
            'AP49',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule replay surfaces [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            'AtlasLedgerReplayService $ledgerReplay',
            "'name' => 'atlas_self_improvement_schedule_report'",
            "'atlas_self_improvement_schedule_report' => \$this->toolResponse(\$id, \$this->selfImprovementScheduleReport(\$arguments))",
            'private function selfImprovementScheduleReport(array $arguments): array',
            '$this->ledgerReplay->selfImprovementScheduleReportForWindow(now()->subHours($hours))',
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP must expose schedule replay read model through atlas_self_improvement_schedule_report [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_schedule_report_tool_exposes_replay_read_model',
            "\$this->assertContains('atlas_self_improvement_schedule_report'",
            "\$this->assertSame('atlas_self_improvement_schedule_report', \$structured['tool'])",
            "\$this->assertFalse(\$structured['writes'])",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP schedule report must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas_self_improvement_schedule_report',
            'AP50',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule replay MCP tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            '...$this->selfImprovementScheduleReplayFindings($hours, $filters)',
            'private function selfImprovementScheduleReplayFindings(int $hours, array $filters = []): array',
            '$this->replay->selfImprovementScheduleReportForWindow(now()->subHours($hours))',
            "'title' => 'Investigar drift recorrente no schedule do Self-Improvement'",
            "'dedupe_key' => 'self-improvement:schedule-replay:'.sha1",
            "'review_required' => (bool) (\$report['review_required'] ?? false)",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must consume schedule replay read model for drift review [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_schedule_replay_drift',
            'recordSelfImprovementScheduleObservation',
            "'self-improvement:schedule-replay:'.sha1('1:invalid_self_improvement_flows_configured')",
            "\$this->assertSame('Investigar drift recorrente no schedule do Self-Improvement', \$finding['title'])",
            "\$this->assertSame(1, data_get(\$finding, 'metadata.warning_count'))",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: schedule replay drift review must be covered [{$token}]";
            }
        }

        foreach ([
            'schedule replay drift',
            'AP51',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Self-Improvement schedule replay drift review [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            '$reviewSignal = $this->selfImprovementScheduleReviewSignal($events, $warningCount, $issueCounts)',
            "'review_signal' => \$reviewSignal",
            'private function selfImprovementScheduleReviewSignal(Collection $events, int $warningCount, array $issueCounts): array',
            "'recommended_action' => 'open_reviewable_self_improvement_schedule_proposal'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: schedule replay must publish canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "\$reviewSignal = (array) (\$report['review_signal'] ?? [])",
            "! (bool) (\$reviewSignal['review_required'] ?? false)",
            "'review_signal' => \$reviewSignal",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Curator must consume canonical schedule replay review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$report, 'review_signal.status')",
            "data_get(\$report, 'review_signal.severity')",
            "data_get(\$report, 'review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: schedule replay review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$finding, 'metadata.review_signal.status')",
            "data_get(\$finding, 'metadata.review_signal.severity')",
            "data_get(\$finding, 'metadata.review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Curator review_signal consumption must be covered [{$token}]";
            }
        }

        foreach ([
            'schedule replay review_signal',
            'AP52',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe canonical schedule replay review_signal [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleReplayReviewSignalSurfaces(): array
    {
        $commandPath = app_path('Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            "data_get(\$report, 'review_signal.status'",
            "data_get(\$report, 'review_signal.severity'",
            "data_get(\$report, 'review_signal.recommended_action'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php: human schedule report must render review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'self_improvement_schedule_replay.review_signal.status')",
            "data_get(\$payload, 'self_improvement_schedule_replay.review_signal.severity')",
            "data_get(\$payload, 'self_improvement_schedule_replay.review_signal.recommended_action')",
            'test_command_human_output_includes_schedule_replay_review_signal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: CLI schedule report review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "self_improvement_schedule_replay.review_signal.status', 'warning'",
            "self_improvement_schedule_replay.review_signal.severity', 'medium'",
            "self_improvement_schedule_replay.review_signal.recommended_action', 'open_reviewable_self_improvement_schedule_proposal'",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: API schedule report review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "self_improvement_schedule_replay.review_signal.status', 'ok'",
            "self_improvement_schedule_replay.review_signal.severity', 'none'",
            "self_improvement_schedule_replay.review_signal.recommended_action', 'none'",
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: Observability schedule replay review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$structured, 'self_improvement_schedule_replay.review_signal.status')",
            "data_get(\$structured, 'self_improvement_schedule_replay.review_signal.severity')",
            "data_get(\$structured, 'self_improvement_schedule_replay.review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP schedule report review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'review_signal surface parity',
            'AP53',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe schedule replay review_signal surface parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanKernelPipelineReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $commandPath = app_path('Console/Commands/AtlasAiKernelPipelineReportCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiKernelPipelineReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiKernelPipelineReportApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            '$reviewSignal = $this->kernelPipelineReviewSignal($health, $events->pluck(\'violations\')->flatten()->filter()->countBy()->all())',
            "'review_signal' => \$reviewSignal",
            'private function kernelPipelineReviewSignal(array $health, array $violationCounts): array',
            "'recommended_action' => 'open_reviewable_kernel_pipeline_contract_proposal'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: Kernel Pipeline replay must publish canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "'review_signal' => \$report['review_signal'] ?? []",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must propagate Kernel Pipeline review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$pipeline, 'review_signal.status'",
            "data_get(\$pipeline, 'review_signal.severity'",
            "data_get(\$pipeline, 'review_signal.recommended_action'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiKernelPipelineReportCommand.php: Kernel Pipeline human report must render review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$report, 'review_signal.status')",
            "data_get(\$report, 'review_signal.severity')",
            "data_get(\$report, 'review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: Kernel Pipeline review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$finding, 'metadata.review_signal.status')",
            "data_get(\$finding, 'metadata.review_signal.severity')",
            "data_get(\$finding, 'metadata.review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Curator Kernel Pipeline review_signal propagation must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel_pipeline.review_signal.status')",
            'test_command_human_output_includes_kernel_pipeline_review_signal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiKernelPipelineReportCommandTest.php: CLI Kernel Pipeline review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "kernel_pipeline.review_signal.status', 'breach'",
            "kernel_pipeline.review_signal.severity', 'high'",
            "kernel_pipeline.review_signal.recommended_action', 'open_reviewable_kernel_pipeline_contract_proposal'",
        ] as $token) {
            if (! str_contains($apiTest, $token) || ! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai: API and Observability Kernel Pipeline review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'kernel pipeline review_signal',
            'AP54',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Kernel Pipeline review_signal [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRepairLoopReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $reportCommandPath = app_path('Console/Commands/AtlasAiRepairReportCommand.php');
        $ledgerCommandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiRepairReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiRepairReportApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $reportCommand = File::exists($reportCommandPath) ? File::get($reportCommandPath) : '';
        $ledgerCommand = File::exists($ledgerCommandPath) ? File::get($ledgerCommandPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            '$reviewSignal = $this->repairReviewSignal($events, $statusCounts, $strategyCounts, $reasonCounts, $requiresHumanReview)',
            "'review_signal' => \$reviewSignal",
            'private function repairReviewSignal(Collection $events, array $statusCounts, array $strategyCounts, array $reasonCounts, bool $requiresHumanReview): array',
            "'recommended_action' => 'open_reviewable_repair_loop_human_review_proposal'",
            "'recommended_action' => 'open_reviewable_repair_loop_policy_proposal'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: Repair Loop replay must publish canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "'review_signal' => \$report['review_signal'] ?? []",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must propagate Repair Loop review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$repair, 'review_signal.status'",
            "data_get(\$repair, 'review_signal.severity'",
            "data_get(\$repair, 'review_signal.recommended_action'",
        ] as $token) {
            if (! str_contains($reportCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiRepairReportCommand.php: Repair report CLI must render review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$repair, 'review_signal.status'",
            "data_get(\$repair, 'review_signal.severity'",
        ] as $token) {
            if (! str_contains($ledgerCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLedgerCommand.php: Ledger CLI repair summary must render review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$report, 'review_signal.status')",
            "data_get(\$report, 'review_signal.severity')",
            "data_get(\$report, 'review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: Repair Loop review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$finding, 'metadata.review_signal.status')",
            "data_get(\$finding, 'metadata.review_signal.severity')",
            "data_get(\$finding, 'metadata.review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Curator Repair Loop review_signal propagation must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel_repair.review_signal.status')",
            'test_command_human_output_includes_repair_review_signal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiRepairReportCommandTest.php: CLI Repair Loop review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "kernel_repair.review_signal.status', 'warning'",
            "kernel_repair.review_signal.severity', 'medium'",
            "kernel_repair.review_signal.recommended_action', 'open_reviewable_repair_loop_human_review_proposal'",
        ] as $token) {
            if (! str_contains($apiTest, $token) || ! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai: API and Observability Repair Loop review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'repair loop review_signal',
            'AP55',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Repair Loop review_signal [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSloReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $commandPath = app_path('Console/Commands/AtlasAiSloCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSloCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSloApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';

        foreach ([
            '$reviewSignal = $this->sloReviewSignal($observations, $worstStatus, $worstSeverity, $failureCount, $stages)',
            "'review_signal' => \$reviewSignal",
            'private function sloReviewSignal(Collection $observations, ?string $worstStatus, ?string $worstSeverity, int $failureCount, array $stages): array',
            "'recommended_action' => 'open_reviewable_slo_regression_proposal'",
            "'recommended_action' => 'open_reviewable_slo_drift_proposal'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: SLO replay must publish canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "'review_signal' => \$report['review_signal'] ?? []",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must propagate SLO review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$slo, 'review_signal.status'",
            "data_get(\$slo, 'review_signal.severity'",
            "data_get(\$slo, 'review_signal.recommended_action'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSloCommand.php: SLO CLI must render review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$report, 'review_signal.status')",
            "data_get(\$report, 'review_signal.severity')",
            "data_get(\$report, 'review_signal.recommended_action')",
            'open_reviewable_slo_regression_proposal',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: SLO review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$finding, 'metadata.review_signal.status')",
            "data_get(\$finding, 'metadata.review_signal.severity')",
            "data_get(\$finding, 'metadata.review_signal.recommended_action')",
            'open_reviewable_slo_regression_proposal',
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Curator SLO review_signal propagation must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel_slo.review_signal.status')",
            'test_command_human_output_includes_slo_review_signal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSloCommandTest.php: SLO CLI review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "kernel_slo.review_signal.status', 'warning'",
            "kernel_slo.review_signal.severity', 'medium'",
            "kernel_slo.review_signal.recommended_action', 'open_reviewable_slo_drift_proposal'",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSloApiTest.php: SLO API review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "kernel_slo.review_signal.status', 'breach'",
            "kernel_slo.review_signal.severity', 'high'",
            "kernel_slo.review_signal.recommended_action', 'open_reviewable_slo_regression_proposal'",
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: Observability SLO review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'slo review_signal',
            'AP56',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe SLO review_signal [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSloMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            "'name' => 'atlas_kernel_slo_report'",
            "'atlas_kernel_slo_report' => \$this->toolResponse(\$id, \$this->kernelSloReport(\$arguments))",
            'private function kernelSloReport(array $arguments): array',
            '$this->ledgerReplay->sloReportForWindow(now()->subHours($hours), null, $filters)',
            'private function kernelSloFilters(array $arguments): array',
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP must expose read-only atlas_kernel_slo_report [{$token}]";
            }
        }

        foreach ([
            'test_kernel_slo_report_tool_exposes_replay_read_model',
            "'name' => 'atlas_kernel_slo_report'",
            "data_get(\$structured, 'kernel_slo.review_signal.status')",
            'open_reviewable_slo_regression_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP SLO report tool must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas_kernel_slo_report',
            'AP57',
        ] as $token) {
            if (! str_contains($docs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe MCP SLO report tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanKernelPipelineMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            "'name' => 'atlas_kernel_pipeline_report'",
            "'atlas_kernel_pipeline_report' => \$this->toolResponse(\$id, \$this->kernelPipelineReport(\$arguments))",
            'private function kernelPipelineReport(array $arguments): array',
            '$this->ledgerReplay->kernelPipelineReportForWindow(now()->subHours($hours), null, $filters)',
            "'kernel_pipeline' => \$report",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP must expose read-only atlas_kernel_pipeline_report [{$token}]";
            }
        }

        foreach ([
            'test_kernel_pipeline_report_tool_exposes_replay_read_model',
            "'name' => 'atlas_kernel_pipeline_report'",
            "data_get(\$structured, 'kernel_pipeline.review_signal.status')",
            'open_reviewable_kernel_pipeline_contract_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP Kernel Pipeline report tool must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas_kernel_pipeline_report',
            'AP58',
        ] as $token) {
            if (! str_contains($docs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe MCP Kernel Pipeline report tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRepairLoopMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            "'name' => 'atlas_repair_loop_report'",
            "'atlas_repair_loop_report' => \$this->toolResponse(\$id, \$this->repairLoopReport(\$arguments))",
            'private function repairLoopReport(array $arguments): array',
            '$this->ledgerReplay->repairReportForWindow(now()->subHours($hours), null, $filters)',
            "'kernel_repair' => \$report",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP must expose read-only atlas_repair_loop_report [{$token}]";
            }
        }

        foreach ([
            'test_repair_loop_report_tool_exposes_replay_read_model',
            "'name' => 'atlas_repair_loop_report'",
            "data_get(\$structured, 'kernel_repair.review_signal.status')",
            'open_reviewable_repair_loop_human_review_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP Repair Loop report tool must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas_repair_loop_report',
            'AP59',
        ] as $token) {
            if (! str_contains($docs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe MCP Repair Loop report tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRepairLoopUnavailableReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiRepairReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiRepairReportApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            "...array_diff_key(\$this->repairEventSummary(collect()), ['events' => true])",
            "'recommended_action' => 'wait_for_repair_loop_evidence'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: Repair Loop unavailable payload must preserve canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel_repair.review_signal.status')",
            "data_get(\$payload, 'kernel_repair.review_signal.recommended_action')",
            'wait_for_repair_loop_evidence',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiRepairReportCommandTest.php: Repair Loop CLI unavailable review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('kernel_repair.review_signal.status', 'unknown')",
            "assertJsonPath('kernel_repair.review_signal.recommended_action', 'wait_for_repair_loop_evidence')",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiRepairReportApiTest.php: Repair Loop API unavailable review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'repair loop unavailable review_signal',
            'AP60',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe Repair Loop unavailable review_signal parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSloUnavailableReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSloCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSloApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            '...$this->sloObservationSummary(new Collection)',
            "'recommended_action' => 'wait_for_slo_evidence'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: SLO unavailable payload must preserve canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel_slo.review_signal.status')",
            "data_get(\$payload, 'kernel_slo.review_signal.recommended_action')",
            'wait_for_slo_evidence',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSloCommandTest.php: SLO CLI unavailable review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('kernel_slo.review_signal.status', 'unknown')",
            "assertJsonPath('kernel_slo.review_signal.recommended_action', 'wait_for_slo_evidence')",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSloApiTest.php: SLO API unavailable review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'slo unavailable review_signal',
            'AP61',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe SLO unavailable review_signal parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanMcpReplayUnavailableReviewSignal(): array
    {
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            'test_replay_report_tools_preserve_review_signal_when_ledger_is_unavailable',
            "'atlas_self_improvement_schedule_report'",
            "'atlas_kernel_slo_report'",
            "'atlas_kernel_pipeline_report'",
            "'atlas_repair_loop_report'",
            'wait_for_next_self_improvement_cycle',
            'wait_for_slo_evidence',
            'wait_for_kernel_pipeline_evidence',
            'wait_for_repair_loop_evidence',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP replay tools must preserve unavailable review_signal [{$token}]";
            }
        }

        foreach ([
            'mcp replay unavailable review_signal',
            'AP62',
        ] as $token) {
            if (! str_contains($docs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe MCP unavailable review_signal parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanScheduleReplayUnavailableShapeParity(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            "...array_diff_key(\$this->selfImprovementScheduleEventSummary(collect()), ['events' => true])",
            "'recent_events' => []",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: unavailable schedule replay must match public shape and omit raw events [{$token}]";
            }
        }

        foreach ([
            "\$this->assertArrayNotHasKey('events', data_get(\$payload, 'self_improvement_schedule_replay'))",
            "data_get(\$payload, 'self_improvement_schedule_replay.review_signal.status')",
            "data_get(\$payload, 'self_improvement_schedule_replay.recent_events')",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportCommandTest.php: CLI unavailable schedule replay shape must be covered [{$token}]";
            }
        }

        foreach ([
            "\$this->assertArrayNotHasKey('events', \$response->json('self_improvement_schedule_replay'))",
            "self_improvement_schedule_replay.review_signal.status', 'unknown'",
            "self_improvement_schedule_replay.recent_events', []",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSelfImprovementScheduleReportApiTest.php: API unavailable schedule replay shape must be covered [{$token}]";
            }
        }

        foreach ([
            'schedule replay unavailable shape parity',
            'AP-63',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe schedule replay unavailable shape parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanMcpReplayWindowContract(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'private function reportWindowHours(array $arguments): int',
            "return \$this->replayInput->hours(\$arguments['hours'] ?? null)",
            '$hours = $this->reportWindowHours($arguments)',
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP replay tools must use one canonical hours normalizer [{$token}]";
            }
        }

        foreach ([
            'test_replay_report_tools_use_canonical_mcp_hours_window',
            "'tool' => 'atlas_self_improvement_schedule_report', 'input' => -5, 'expected' => 1",
            "'tool' => 'atlas_kernel_slo_report', 'input' => 9999, 'expected' => 720",
            "'tool' => 'atlas_kernel_pipeline_report', 'input' => ['bad'], 'expected' => 24",
            "'tool' => 'atlas_repair_loop_report', 'input' => '12', 'expected' => 12",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP replay hours normalization must be covered [{$token}]";
            }
        }

        foreach ([
            'mcp replay window contract',
            'AP-64',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe MCP replay window contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanMcpReplayFilterContract(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            "return \$this->onlyScalarFilters(\$arguments, ['domain', 'flow', 'surface_id', 'provider', 'model', 'runtime', 'tool_id'])",
            'private function onlyScalarFilters(array $arguments, array $allowed): array',
            'return $this->replayInput->scalarFilters($arguments, $allowed)',
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP replay filters must share canonical scalar filter normalizer [{$token}]";
            }
        }

        foreach ([
            'test_replay_report_tools_use_canonical_scalar_filter_contract',
            "'expected' => ['domain' => 'programming', 'tool_id' => 'phpstan']",
            "'expected' => ['status' => 'rejected', 'emitter_stage' => 'atlas.test']",
            "'expected' => ['status' => 'completed']",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP replay scalar filter normalization must be covered [{$token}]";
            }
        }

        foreach ([
            'mcp replay filter contract',
            'AP-65',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe MCP replay filter contract [{$token}]";
            }
        }

        return $violations;
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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

        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
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
    private function scanSelfImprovementRuntimeWindowContract(): array
    {
        $inputPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementInput.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'public const DEFAULT_REVIEW_WINDOW_HOURS = 24',
            'public const MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS = 168',
            'public const MAX_FINDINGS_PER_RUN = 20',
            'int $hours = self::DEFAULT_REVIEW_WINDOW_HOURS',
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->reviewWindowHours($hours)',
            '$this->input->findingsLimit($limit)',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement autonomous runtime limits must be explicit [{$token}]";
            }
        }

        foreach ([
            'final class AtlasSelfImprovementInput',
            'public const DEFAULT_FINDINGS_LIMIT = 5',
            'public function reviewWindowHours(',
            'public function findingsLimit(',
            'public function runtimeOptions(',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementInput.php: Self-Improvement input contract is incomplete [{$token}]";
            }
        }

        if (str_contains($runtime, 'min(168, $hours)') || str_contains($runtime, 'min(20, $limit)')) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement runtime must not use magic numeric limits for hours/limit';
        }

        foreach ([
            'test_nightly_review_uses_explicit_autonomous_window_and_limit_contract',
            'test_command_plan_only_uses_shared_self_improvement_input_contract',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
            'AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Self-Improvement runtime window contract must be covered [{$token}]";
            }
        }

        foreach ([
            'self-improvement runtime window contract',
            'AP-69',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe Self-Improvement runtime window contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementScheduleWindowContract(): array
    {
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $schedule = File::exists($schedulePath) ? File::get($schedulePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS',
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->reviewWindowHours(',
            '$this->input->findingsLimit(',
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: schedule must reuse Self-Improvement runtime limits [{$token}]";
            }
        }

        if (str_contains($schedule, 'min(168,') || str_contains($schedule, 'min(20,')) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: schedule must not duplicate Self-Improvement numeric limits';
        }

        foreach ([
            'use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRuntime;',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php: schedule runtime limit reuse must be covered [{$token}]";
            }
        }

        foreach ([
            'self-improvement schedule window contract',
            'AP-70',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe Self-Improvement schedule window contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSelfImprovementOrchestratorWindowContract(): array
    {
        $orchestratorPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php');
        $testPath = base_path('tests/Unit/Ai/AtlasSelfImprovementOrchestratorTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS',
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->runtimeOptions(',
            'AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT',
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php: orchestrator must reuse Self-Improvement runtime limits [{$token}]";
            }
        }

        if (str_contains($orchestrator, 'min(168,') || str_contains($orchestrator, 'min(20,')) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php: orchestrator must not duplicate Self-Improvement numeric limits';
        }

        foreach ([
            'test_flow_plan_reuses_runtime_window_and_limit_contract',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasSelfImprovementOrchestratorTest.php: orchestrator runtime limit reuse must be covered [{$token}]";
            }
        }

        foreach ([
            'self-improvement orchestrator window contract',
            'AP-71',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe Self-Improvement orchestrator window contract [{$token}]";
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasAiRuntimeSettingsTest.php: runtime budget window contract must be covered [{$token}]";
            }
        }

        foreach ([
            'runtime budget window contract',
            'AP-73',
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
    private function scanProgrammingIterationPolicyContract(): array
    {
        $policyPath = app_path('Services/Ai/Programming/ProgrammingIterationPolicy.php');
        $cliPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $workflowPath = app_path('Services/Ai/Cli/AtlasCliDevWorkflowService.php');
        $orchestratorPath = app_path('Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $surfaceFactoryPath = app_path('Services/Ai/Programming/ProgrammingSurfaceContractFactory.php');
        $surfaceBuilderPath = app_path('Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php');
        $policyServicePath = app_path('Services/Ai/AtlasAiPolicyService.php');
        $workerPath = app_path('Services/Ai/AiWorker.php');
        $executionRequestPath = app_path('Services/Ai/Programming/ProgrammingExecutionRequest.php');
        $chatCommandPath = app_path('Console/Commands/AiChatCommand.php');
        $testPath = base_path('tests/Unit/Ai/Programming/ProgrammingIterationPolicyTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $policy = File::exists($policyPath) ? File::get($policyPath) : '';
        $cli = File::exists($cliPath) ? File::get($cliPath) : '';
        $workflow = File::exists($workflowPath) ? File::get($workflowPath) : '';
        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $surfaceFactory = File::exists($surfaceFactoryPath) ? File::get($surfaceFactoryPath) : '';
        $surfaceBuilder = File::exists($surfaceBuilderPath) ? File::get($surfaceBuilderPath) : '';
        $policyService = File::exists($policyServicePath) ? File::get($policyServicePath) : '';
        $worker = File::exists($workerPath) ? File::get($workerPath) : '';
        $executionRequest = File::exists($executionRequestPath) ? File::get($executionRequestPath) : '';
        $chatCommand = File::exists($chatCommandPath) ? File::get($chatCommandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class ProgrammingIterationPolicy',
            'public const MAX_ITERATIONS = 10',
            'public const DEFAULT_DEV_ITERATIONS = 3',
            'public const MIN_COMPLETE_ITERATIONS = 2',
            'public const DEFAULT_FORGE_ITERATIONS = 5',
            'public const MIN_FORGE_ITERATIONS = 5',
            'public const MIN_REPAIR_ITERATIONS = 3',
            'public static function normalize(',
            'public static function forProfile(',
            'public static function forExecutionPolicy(',
            'public static function forRepairPolicy(',
        ] as $token) {
            if (! str_contains($policy, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingIterationPolicy.php: programming iteration policy contract is incomplete [{$token}]";
            }
        }

        foreach ([
            ['ProgrammingIterationPolicy::forProfile($this->option(\'max-iterations\'), $programmingProfile)', $cli],
            ['ProgrammingIterationPolicy::normalize($maxIterations)', $workflow],
            ['ProgrammingIterationPolicy::forExecutionPolicy(', $orchestrator],
            ['ProgrammingIterationPolicy::forRepairPolicy(', $orchestrator],
            ['ProgrammingIterationPolicy::normalize($operatorOptions[\'max_iterations\'] ?? null)', $surfaceFactory],
            ['ProgrammingIterationPolicy::normalize($maxIterations)', $surfaceBuilder],
            ['ProgrammingIterationPolicy::forExecutionPolicy(', $policyService],
            ['ProgrammingIterationPolicy::forRepairPolicy(', $worker],
            ['ProgrammingIterationPolicy::forExecutionPolicy(', $executionRequest],
            ['ProgrammingIterationPolicy::forExecutionPolicy(', $chatCommand],
        ] as [$token, $contents]) {
            if (! str_contains($contents, $token)) {
                $violations[] = "Programming iteration policy consumer missing shared normalizer [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_programming_iterations_with_canonical_caps',
            'test_profile_and_execution_policy_minimums_are_explicit',
            'test_programming_execution_request_uses_same_iteration_policy_for_harness_attempts',
            'ProgrammingIterationPolicy::MAX_ITERATIONS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Programming/ProgrammingIterationPolicyTest.php: programming iteration policy contract must be covered [{$token}]";
            }
        }

        foreach ([
            'programming iteration policy contract',
            'AP-77',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe programming iteration policy contract [{$token}]";
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
    private function scanProviderProjectionAuditInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Provider/ProviderProjectionAuditInput.php');
        $servicePath = app_path('Services/Ai/AtlasProviderProjectionAuditService.php');
        $testPath = base_path('tests/Unit/Ai/Provider/ProviderProjectionAuditInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class ProviderProjectionAuditInput',
            'public const DEFAULT_AUDIT_LIMIT = 50',
            'public const MAX_AUDIT_LIMIT = 200',
            'public const DEFAULT_SUMMARY_DAYS = 30',
            'public const MAX_SUMMARY_DAYS = 365',
            'public const DEFAULT_PURGE_OLDER_THAN_DAYS = 90',
            'public const MAX_PURGE_OLDER_THAN_DAYS = 3650',
            'public function auditLimit(',
            'public function summaryDays(',
            'public function purgeOlderThanDays(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Provider/ProviderProjectionAuditInput.php: provider projection audit input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly ProviderProjectionAuditInput $input',
            '$this->input->auditLimit(',
            '$this->input->summaryDays(',
            '$this->input->purgeOlderThanDays(',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/AtlasProviderProjectionAuditService.php: provider projection audit must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_provider_projection_audit_windows_with_canonical_caps',
            'ProviderProjectionAuditInput::MAX_AUDIT_LIMIT',
            'ProviderProjectionAuditInput::MAX_PURGE_OLDER_THAN_DAYS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Provider/ProviderProjectionAuditInputTest.php: provider projection audit input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'provider projection audit input contract',
            'AP-80',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe provider projection audit input contract [{$token}]";
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';
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
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';
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
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';
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
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';
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
    private function scanProviderProjectionInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Provider/ProviderProjectionInput.php');
        $servicePath = app_path('Services/Ai/AtlasProviderProjectionService.php');
        $testPath = base_path('tests/Unit/Ai/Provider/ProviderProjectionInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class ProviderProjectionInput',
            'public const DEFAULT_MAX_LINES = 80',
            'public const MAX_MAX_LINES = 240',
            'public const DEFAULT_MEMORY_LIMIT = 18',
            'public const MAX_MEMORY_LIMIT = 80',
            'public const DEFAULT_MEMORY_CHARS = 220',
            'public const MAX_MEMORY_CHARS = 1200',
            'public function maxLines(',
            'public function memoryLimit(',
            'public function memoryChars(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Provider/ProviderProjectionInput.php: provider projection input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?ProviderProjectionInput $input = null',
            '$this->projectionInput()->maxLines($options[\'max_lines\'] ?? null)',
            '$this->projectionInput()->memoryLimit($options[\'memory_limit\'] ?? null)',
            '$this->projectionInput()->memoryChars()',
            'private function projectionInput(): ProviderProjectionInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/AtlasProviderProjectionService.php: provider projection must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_provider_projection_limits_with_canonical_caps',
            'ProviderProjectionInput::MAX_MAX_LINES',
            'ProviderProjectionInput::MAX_MEMORY_LIMIT',
            'ProviderProjectionInput::MAX_MEMORY_CHARS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Provider/ProviderProjectionInputTest.php: provider projection input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'provider projection input contract',
            'AP-87',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe provider projection input contract [{$token}]";
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
    private function scanEngineeringHarnessRunnerInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringHarnessRunnerInput.php');
        $runnerPath = app_path('Services/Engineering/EngineeringHarnessRunnerService.php');
        $testPath = base_path('tests/Unit/EngineeringHarnessRunnerInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $runner = File::exists($runnerPath) ? File::get($runnerPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class EngineeringHarnessRunnerInput',
            'public const DEFAULT_MAX_ATTEMPTS = 1',
            'public const MAX_MAX_ATTEMPTS = 10',
            'public function maxAttempts(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessRunnerInput.php: engineering harness runner input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringHarnessRunnerInput $input = null',
            '$this->runnerInput()->maxAttempts($options[\'max_attempts\'] ?? null)',
            '$this->runnerInput()->maxAttempts($maxAttempts)',
            '$this->runnerInput()->maxAttempts($requested[\'max_attempts\'] ?? null)',
            'private function runnerInput(): EngineeringHarnessRunnerInput',
        ] as $token) {
            if (! str_contains($runner, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessRunnerService.php: engineering harness runner must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_harness_runner_attempt_limits',
            'EngineeringHarnessRunnerInput::DEFAULT_MAX_ATTEMPTS',
            'EngineeringHarnessRunnerInput::MAX_MAX_ATTEMPTS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringHarnessRunnerInputTest.php: engineering harness runner input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering harness runner input contract',
            'AP-89',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering harness runner input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringHarnessabilityInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringHarnessabilityInput.php');
        $servicePath = app_path('Services/Engineering/EngineeringHarnessabilityService.php');
        $testPath = base_path('tests/Unit/EngineeringHarnessabilityInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class EngineeringHarnessabilityInput',
            'public const DEFAULT_CALIBRATION_LIMIT = 300',
            'public const MAX_CALIBRATION_LIMIT = 1000',
            'public function calibrationLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessabilityInput.php: engineering harnessability input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringHarnessabilityInput $input = null',
            '$this->harnessabilityInput()->calibrationLimit($options[\'limit\'] ?? null)',
            'private function harnessabilityInput(): EngineeringHarnessabilityInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessabilityService.php: engineering harnessability must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_harnessability_calibration_limit',
            'EngineeringHarnessabilityInput::DEFAULT_CALIBRATION_LIMIT',
            'EngineeringHarnessabilityInput::MAX_CALIBRATION_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringHarnessabilityInputTest.php: engineering harnessability input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering harnessability input contract',
            'AP-90',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering harnessability input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringDockerHarnessInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringDockerHarnessInput.php');
        $servicePath = app_path('Services/Engineering/EngineeringDockerHarnessService.php');
        $testPath = base_path('tests/Unit/EngineeringDockerHarnessInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class EngineeringDockerHarnessInput',
            'public const DEFAULT_HEALTHCHECK_TIMEOUT_SECONDS = 45',
            'public const MAX_HEALTHCHECK_TIMEOUT_SECONDS = 600',
            'public const DEFAULT_ARTIFACT_MAX_FILES = 100',
            'public const MAX_ARTIFACT_MAX_FILES = 1000',
            'public const DEFAULT_ARTIFACT_MAX_BYTES = 10_485_760',
            'public const MAX_ARTIFACT_MAX_BYTES = 524_288_000',
            'public const DEFAULT_CACHE_RETENTION_DAYS = 14',
            'public const MAX_CACHE_RETENTION_DAYS = 365',
            'public const DEFAULT_ARTIFACT_RETENTION_DAYS = 30',
            'public const MAX_ARTIFACT_RETENTION_DAYS = 365',
            'public function healthcheckTimeoutSeconds(',
            'public function artifactMaxFiles(',
            'public function artifactMaxBytes(',
            'public function cacheRetentionDays(',
            'public function artifactRetentionDays(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringDockerHarnessInput.php: engineering docker harness input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringDockerHarnessInput $input = null',
            '$this->dockerInput()->healthcheckTimeoutSeconds($options[\'docker_healthcheck_timeout\'] ?? null)',
            '$this->dockerInput()->artifactMaxFiles($options[\'docker_artifact_max_files\'] ?? null)',
            '$this->dockerInput()->artifactMaxBytes($options[\'docker_artifact_max_bytes\'] ?? null)',
            '$this->dockerInput()->cacheRetentionDays($options[\'cache_retention_days\'] ?? null)',
            '$this->dockerInput()->artifactRetentionDays($options[\'artifact_retention_days\'] ?? null)',
            'private function dockerInput(): EngineeringDockerHarnessInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringDockerHarnessService.php: engineering docker harness must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_docker_harness_limits',
            'EngineeringDockerHarnessInput::MAX_HEALTHCHECK_TIMEOUT_SECONDS',
            'EngineeringDockerHarnessInput::MAX_ARTIFACT_MAX_FILES',
            'EngineeringDockerHarnessInput::MAX_ARTIFACT_MAX_BYTES',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringDockerHarnessInputTest.php: engineering docker harness input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering docker harness input contract',
            'AP-91',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering docker harness input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringTestMatrixInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringTestMatrixInput.php');
        $servicePath = app_path('Services/Engineering/EngineeringTestMatrixService.php');
        $testPath = base_path('tests/Unit/EngineeringTestMatrixInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class EngineeringTestMatrixInput',
            'public const DEFAULT_QUALITY_SCAN_TIMEOUT_SECONDS = 300',
            'public const MAX_QUALITY_SCAN_TIMEOUT_SECONDS = 3600',
            'public const DEFAULT_VISUAL_SMOKE_TIMEOUT_SECONDS = 45',
            'public const MAX_VISUAL_SMOKE_TIMEOUT_SECONDS = 1800',
            'public const DEFAULT_VISUAL_ARTIFACT_MAX_FILES = 200',
            'public const MAX_VISUAL_ARTIFACT_MAX_FILES = 2000',
            'public const DEFAULT_VISUAL_ARTIFACT_MAX_BYTES = 52_428_800',
            'public const MAX_VISUAL_ARTIFACT_MAX_BYTES = 1_073_741_824',
            'public const DEFAULT_QUALITY_ARTIFACT_MAX_FILES = 100',
            'public const MAX_QUALITY_ARTIFACT_MAX_FILES = 1000',
            'public const DEFAULT_QUALITY_ARTIFACT_MAX_BYTES = 10_485_760',
            'public const MAX_QUALITY_ARTIFACT_MAX_BYTES = 524_288_000',
            'public function qualityScanTimeoutSeconds(',
            'public function visualSmokeTimeoutSeconds(',
            'public function visualArtifactMaxFiles(',
            'public function visualArtifactMaxBytes(',
            'public function qualityArtifactMaxFiles(',
            'public function qualityArtifactMaxBytes(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringTestMatrixInput.php: engineering test matrix input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringTestMatrixInput $input = null',
            '$this->matrixInput()->qualityScanTimeoutSeconds()',
            '$this->matrixInput()->visualSmokeTimeoutSeconds()',
            '$this->matrixInput()->visualArtifactMaxFiles()',
            '$this->matrixInput()->visualArtifactMaxBytes()',
            '$this->matrixInput()->qualityArtifactMaxFiles()',
            '$this->matrixInput()->qualityArtifactMaxBytes()',
            'private function matrixInput(): EngineeringTestMatrixInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringTestMatrixService.php: engineering test matrix must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_test_matrix_limits',
            'EngineeringTestMatrixInput::MAX_QUALITY_SCAN_TIMEOUT_SECONDS',
            'EngineeringTestMatrixInput::MAX_VISUAL_ARTIFACT_MAX_FILES',
            'EngineeringTestMatrixInput::MAX_QUALITY_ARTIFACT_MAX_BYTES',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringTestMatrixInputTest.php: engineering test matrix input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering test matrix input contract',
            'AP-92',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering test matrix input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringClaudeCodeBaselineInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringClaudeCodeBaselineInput.php');
        $servicePath = app_path('Services/Engineering/EngineeringClaudeCodeBaselineRunnerService.php');
        $testPath = base_path('tests/Unit/EngineeringClaudeCodeBaselineInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class EngineeringClaudeCodeBaselineInput',
            'public const DEFAULT_TIMEOUT_SECONDS = 900',
            'public const MAX_TIMEOUT_SECONDS = 3600',
            'public const DEFAULT_VALIDATION_TIMEOUT_SECONDS = 300',
            'public const MAX_VALIDATION_TIMEOUT_SECONDS = 1800',
            'public function runTimeoutSeconds(',
            'public function validationTimeoutSeconds(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringClaudeCodeBaselineInput.php: engineering Claude Code baseline input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringClaudeCodeBaselineInput $input = null',
            '$this->baselineInput()->runTimeoutSeconds($runnerOptions)',
            '$this->baselineInput()->validationTimeoutSeconds($runnerOptions)',
            'private function baselineInput(): EngineeringClaudeCodeBaselineInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringClaudeCodeBaselineRunnerService.php: Claude Code baseline runner must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_claude_code_baseline_timeouts',
            'EngineeringClaudeCodeBaselineInput::MAX_TIMEOUT_SECONDS',
            'EngineeringClaudeCodeBaselineInput::MAX_VALIDATION_TIMEOUT_SECONDS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringClaudeCodeBaselineInputTest.php: Claude Code baseline input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering Claude Code baseline input contract',
            'AP-93',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering Claude Code baseline input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringBenchmarkInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringBenchmarkInput.php');
        $servicePath = app_path('Services/Engineering/EngineeringBenchmarkService.php');
        $testPath = base_path('tests/Unit/EngineeringBenchmarkInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class EngineeringBenchmarkInput',
            'public const DEFAULT_PROMOTE_RECENT_RUNS_LIMIT = 10',
            'public const MAX_PROMOTE_RECENT_RUNS_LIMIT = 100',
            'public const DEFAULT_TREND_LIMIT = 50',
            'public const MAX_TREND_LIMIT = 200',
            'public const DEFAULT_FAIR_CLAUDE_REPORT_LIMIT = 20',
            'public const MAX_FAIR_CLAUDE_REPORT_LIMIT = 200',
            'public const DEFAULT_CALIBRATE_SUITE_LIMIT = 200',
            'public const MAX_CALIBRATE_SUITE_LIMIT = 500',
            'public const MAX_FAIR_CLAUDE_COMPARISONS = 200',
            'public function promoteRecentRunsLimit(',
            'public function trendLimit(',
            'public function fairClaudeReportLimit(',
            'public function calibrateSuiteLimit(',
            'public function fairClaudeComparisonTakeLimit(',
            'public function fairClaudeScanLimit(',
            'public function fairClaudeBatchSize(',
            'public function minSourceScore(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringBenchmarkInput.php: engineering benchmark input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringBenchmarkInput $input = null',
            '$this->benchmarkInput()->promoteRecentRunsLimit($options[\'limit\'] ?? null)',
            '$this->benchmarkInput()->minSourceScore($options[\'min_source_score\'] ?? null)',
            '$this->benchmarkInput()->trendLimit($options[\'limit\'] ?? null)',
            '$this->benchmarkInput()->fairClaudeReportLimit($options[\'limit\'] ?? null)',
            '$this->benchmarkInput()->fairClaudeScanLimit($limit)',
            '$this->benchmarkInput()->fairClaudeBatchSize($scanLimit)',
            '$this->benchmarkInput()->calibrateSuiteLimit($options[\'limit\'] ?? null)',
            '$this->benchmarkInput()->fairClaudeComparisonTakeLimit($limit)',
            'private function benchmarkInput(): EngineeringBenchmarkInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringBenchmarkService.php: engineering benchmark must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_benchmark_limits',
            'EngineeringBenchmarkInput::MAX_PROMOTE_RECENT_RUNS_LIMIT',
            'EngineeringBenchmarkInput::MAX_FAIR_CLAUDE_REPORT_LIMIT',
            'EngineeringBenchmarkInput::MAX_FAIR_CLAUDE_COMPARISONS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringBenchmarkInputTest.php: engineering benchmark input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering benchmark input contract',
            'AP-94',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering benchmark input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEngineeringContextIntelligenceInputContract(): array
    {
        $inputPath = app_path('Services/Engineering/EngineeringContextIntelligenceInput.php');
        $knowledgePath = app_path('Services/Engineering/EngineeringKnowledgeBaseService.php');
        $codePath = app_path('Services/Engineering/EngineeringCodeIntelligenceService.php');
        $artifactPath = app_path('Services/Engineering/EngineeringRunArtifactService.php');
        $commandPath = app_path('Console/Commands/AtlasEngineeringKnowledgeCommand.php');
        $testPath = base_path('tests/Unit/EngineeringContextIntelligenceInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $knowledge = File::exists($knowledgePath) ? File::get($knowledgePath) : '';
        $code = File::exists($codePath) ? File::get($codePath) : '';
        $artifact = File::exists($artifactPath) ? File::get($artifactPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class EngineeringContextIntelligenceInput',
            'public const DEFAULT_KNOWLEDGE_LIMIT = 50',
            'public const MAX_KNOWLEDGE_LIMIT = 200',
            'public const DEFAULT_CODE_LIMIT = 50',
            'public const MAX_CODE_LIMIT = 500',
            'public const DEFAULT_EVIDENCE_HISTORY_LIMIT = 100',
            'public const MAX_EVIDENCE_HISTORY_LIMIT = 200',
            'public function knowledgeLimit(',
            'public function codeLimit(',
            'public function evidenceHistoryLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringContextIntelligenceInput.php: engineering context intelligence input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?EngineeringContextIntelligenceInput $input = null',
            '$this->contextInput()->knowledgeLimit($limit)',
            'private function contextInput(): EngineeringContextIntelligenceInput',
        ] as $token) {
            if (! str_contains($knowledge, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringKnowledgeBaseService.php: knowledge base must use shared context intelligence input [{$token}]";
            }
        }

        foreach ([
            '?EngineeringContextIntelligenceInput $input = null',
            '$this->contextInput()->codeLimit($options[\'limit\'] ?? null)',
            '$this->contextInput()->codeLimit($limit)',
            'private function contextInput(): EngineeringContextIntelligenceInput',
        ] as $token) {
            if (! str_contains($code, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringCodeIntelligenceService.php: code intelligence must use shared context intelligence input [{$token}]";
            }
        }

        foreach ([
            '?EngineeringContextIntelligenceInput $input = null',
            '$this->contextInput()->evidenceHistoryLimit($limit)',
            '$this->contextInput()->evidenceHistoryLimit(200)',
            '$this->contextInput()->evidenceHistoryLimit()',
            'private function contextInput(): EngineeringContextIntelligenceInput',
        ] as $token) {
            if (! str_contains($artifact, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringRunArtifactService.php: run artifacts must use shared context intelligence input [{$token}]";
            }
        }

        foreach ([
            'EngineeringContextIntelligenceInput $input',
            '$this->contextInput()->knowledgeLimit($this->option(\'limit\'))',
            '$this->contextInput()->codeLimit($this->option(\'limit\'))',
            'private function contextInput(): EngineeringContextIntelligenceInput',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasEngineeringKnowledgeCommand.php: engineering knowledge command must use shared context intelligence input [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_engineering_context_intelligence_limits',
            'EngineeringContextIntelligenceInput::MAX_KNOWLEDGE_LIMIT',
            'EngineeringContextIntelligenceInput::MAX_CODE_LIMIT',
            'EngineeringContextIntelligenceInput::MAX_EVIDENCE_HISTORY_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/EngineeringContextIntelligenceInputTest.php: engineering context intelligence input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'engineering context intelligence input contract',
            'AP-95',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe engineering context intelligence input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliLimitInputContract(): array
    {
        $inputPath = app_path('Console/Commands/Support/AtlasCliLimitInput.php');
        $toolsPath = app_path('Console/Commands/AtlasToolsCommand.php');
        $tracePath = app_path('Console/Commands/AtlasCliTraceCommand.php');
        $inboxPath = app_path('Console/Commands/AtlasCliInboxCommand.php');
        $benchmarkReportPath = app_path('Console/Commands/AtlasEngineeringBenchmarkReportCommand.php');
        $benchmarkCalibratePath = app_path('Console/Commands/AtlasEngineeringBenchmarkCalibrateCommand.php');
        $testPath = base_path('tests/Unit/Console/AtlasCliLimitInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $tools = File::exists($toolsPath) ? File::get($toolsPath) : '';
        $trace = File::exists($tracePath) ? File::get($tracePath) : '';
        $inbox = File::exists($inboxPath) ? File::get($inboxPath) : '';
        $benchmarkReport = File::exists($benchmarkReportPath) ? File::get($benchmarkReportPath) : '';
        $benchmarkCalibrate = File::exists($benchmarkCalibratePath) ? File::get($benchmarkCalibratePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class AtlasCliLimitInput',
            'public const DEFAULT_LIST_LIMIT = 20',
            'public const DEFAULT_INBOX_LIMIT = 50',
            'public const DEFAULT_RELEASE_GATE_LIMIT = 100',
            'public const MAX_STANDARD_LIMIT = 100',
            'public const MAX_RELEASE_GATE_LIMIT = 200',
            'public const MAX_BENCHMARK_CALIBRATION_LIMIT = 500',
            'public function standardLimit(',
            'public function releaseGateLimit(',
            'public function benchmarkReportLimit(',
            'public function benchmarkCalibrationLimit(',
            'public function inboxLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Console/Commands/Support/AtlasCliLimitInput.php: CLI limit input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'AtlasCliLimitInput $limits',
            '$this->cliLimits()->standardLimit($this->option(\'limit\'))',
            '$this->cliLimits()->releaseGateLimit($this->option(\'limit\'))',
            'private function cliLimits(): AtlasCliLimitInput',
        ] as $token) {
            if (! str_contains($tools, $token)) {
                $violations[] = "app/Console/Commands/AtlasToolsCommand.php: tools command must use shared CLI limit input [{$token}]";
            }
        }

        foreach ([
            '?AtlasCliLimitInput $limits = null',
            '$this->cliLimits()->standardLimit($this->option(\'limit\'))',
            'private function cliLimits(): AtlasCliLimitInput',
        ] as $token) {
            if (! str_contains($trace, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliTraceCommand.php: trace command must use shared CLI limit input [{$token}]";
            }
        }

        foreach ([
            '?AtlasCliLimitInput $limits = null',
            '$this->cliLimits()->inboxLimit($this->option(\'limit\'))',
            'private function cliLimits(): AtlasCliLimitInput',
        ] as $token) {
            if (! str_contains($inbox, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliInboxCommand.php: inbox command must use shared CLI limit input [{$token}]";
            }
        }

        if (! str_contains($benchmarkReport, '$limits->benchmarkReportLimit($this->option(\'limit\'))')) {
            $violations[] = 'app/Console/Commands/AtlasEngineeringBenchmarkReportCommand.php: benchmark report must use shared CLI limit input';
        }

        if (! str_contains($benchmarkCalibrate, '$limits->benchmarkCalibrationLimit($this->option(\'limit\'))')) {
            $violations[] = 'app/Console/Commands/AtlasEngineeringBenchmarkCalibrateCommand.php: benchmark calibrate must use shared CLI limit input';
        }

        foreach ([
            'test_normalizes_shared_cli_limits',
            'AtlasCliLimitInput::MAX_STANDARD_LIMIT',
            'AtlasCliLimitInput::MAX_RELEASE_GATE_LIMIT',
            'AtlasCliLimitInput::MAX_BENCHMARK_CALIBRATION_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Console/AtlasCliLimitInputTest.php: CLI limit input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'CLI limit input contract',
            'AP-96',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe CLI limit input contract [{$token}]";
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

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
    private function scanSelfImprovementInputContract(): array
    {
        $inputPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementInput.php');
        $commandPath = app_path('Console/Commands/AtlasAiSelfImproveCommand.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $orchestratorPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php');
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasSelfImprovementInputTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $schedule = File::exists($schedulePath) ? File::get($schedulePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class AtlasSelfImprovementInput',
            'public const DEFAULT_FINDINGS_LIMIT = 5',
            'public function reviewWindowHours(',
            'public function findingsLimit(',
            'public function runtimeOptions(',
            'AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementInput.php: self-improvement input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'AtlasSelfImprovementInput $input',
            '$input->runtimeOptions([',
            "'hours' => \$this->option('hours')",
            "'limit' => \$this->option('limit')",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImproveCommand.php: command must use self-improvement input contract [{$token}]";
            }
        }

        if (str_contains($command, "(int) \$this->option('hours')") || str_contains($command, "(int) \$this->option('limit')")) {
            $violations[] = 'app/Console/Commands/AtlasAiSelfImproveCommand.php: command must not cast hours/limit outside AtlasSelfImprovementInput';
        }

        foreach ([
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->reviewWindowHours($hours)',
            '$this->input->findingsLimit($limit)',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: runtime must use self-improvement input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->runtimeOptions([',
            'AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT',
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php: orchestrator must use self-improvement input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly AtlasSelfImprovementInput $input',
            '$this->input->reviewWindowHours(',
            '$this->input->findingsLimit(',
            'AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT',
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: schedule must use self-improvement input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_self_improvement_runtime_options',
            'AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasSelfImprovementInputTest.php: self-improvement input contract must be covered [{$token}]";
            }
        }

        if (! str_contains($runtimeTest, 'test_command_plan_only_uses_shared_self_improvement_input_contract')) {
            $violations[] = 'tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: command normalization must be covered by self-improvement input contract test';
        }

        foreach ([
            'self-improvement input contract',
            'AP-98',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe self-improvement input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerEnvelopeInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeInput.php');
        $reportPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiLedgerController.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/KernelLedgerEnvelopeInputTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiLedgerCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiLedgerApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $report = File::exists($reportPath) ? File::get($reportPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'final class KernelLedgerEnvelopeInput',
            'public const DEFAULT_EVENT_LIMIT = 100',
            'public const MAX_EVENT_LIMIT = 500',
            'return max(1, min(self::MAX_EVENT_LIMIT, (int) $value))',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeInput.php: ledger envelope input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly KernelLedgerEnvelopeInput $input',
            '$this->input->eventLimit($limit)',
        ] as $token) {
            if (! str_contains($report, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php: Ledger report must use shared envelope input [{$token}]";
            }
        }

        foreach ([
            "'max:'.KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT",
            'KernelLedgerEnvelopeReportService $reports',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiLedgerController.php: Ledger API must use shared envelope input [{$token}]";
            }
        }

        foreach ([
            'test_event_limit_normalizes_with_canonical_ledger_limits',
            'KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT',
            'KernelLedgerEnvelopeInput::DEFAULT_EVENT_LIMIT',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/KernelLedgerEnvelopeInputTest.php: ledger envelope input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'test_command_uses_canonical_ledger_event_limit_contract',
            'KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiLedgerCommandTest.php: Ledger CLI limit contract must be covered [{$token}]";
            }
        }

        foreach ([
            'test_ledger_api_uses_canonical_event_limit_contract',
            'KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiLedgerApiTest.php: Ledger API limit contract must be covered [{$token}]";
            }
        }

        foreach ([
            'ledger envelope input contract',
            'AP-74',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe ledger envelope input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerEnvelopeReportContract(): array
    {
        $reportPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php');
        $commandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiLedgerController.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/KernelLedgerEnvelopeReportServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $report = File::exists($reportPath) ? File::get($reportPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'class KernelLedgerEnvelopeReportService',
            'public function report(string $envelopeId, mixed $limit = null, bool $includeSlo = false, bool $includeRepair = false, bool $includeKernel = false): array',
            "'status' => 'ledger_table_missing'",
            '\'filters\' => $filters',
            'private function eventPayload(array $event): array',
            '$this->replay->sloReportForEnvelope($envelopeId)',
            '$this->replay->repairReportForEnvelope($envelopeId)',
            '$this->replay->kernelPipelineReportForEnvelope($envelopeId)',
        ] as $token) {
            if (! str_contains($report, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php: ledger envelope report contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'KernelLedgerEnvelopeReportService $reports',
            '$reports->report(',
            'includeKernel: (bool) $this->option(\'kernel\')',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLedgerCommand.php: Ledger CLI must consume shared report service [{$token}]";
            }
        }

        foreach ([
            'KernelLedgerEnvelopeReportService $reports',
            '$reports->report(',
            "\$payload['status'] === 'ledger_table_missing' ? 503 : 200",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiLedgerController.php: Ledger API must consume shared report service [{$token}]";
            }
        }

        foreach ([
            'test_report_projects_envelope_events_with_canonical_filters',
            'test_report_preserves_shape_when_ledger_table_is_missing',
            'KernelLedgerEnvelopeReportService::class',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/KernelLedgerEnvelopeReportServiceTest.php: ledger envelope report service must be covered [{$token}]";
            }
        }

        foreach ([
            'ledger envelope report contract',
            'AP-75',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe ledger envelope report contract [{$token}]";
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
    private function scanProviderMemoryPrivacy(): array
    {
        $privacyPath = app_path('Services/Ai/AtlasMemoryPrivacyService.php');
        $projectionPath = app_path('Services/Ai/AtlasProviderProjectionService.php');
        $openBrainPath = app_path('Services/Ai/AtlasHybridMemoryRetrievalService.php');

        $violations = [];

        if (! File::exists($privacyPath)) {
            return ["missing privacy service [{$privacyPath}]"];
        }

        $privacy = File::get($privacyPath);
        $projection = File::exists($projectionPath) ? File::get($projectionPath) : '';
        $openBrain = File::exists($openBrainPath) ? File::get($openBrainPath) : '';

        $checks = [
            'providerDecision exposes auditable privacy decision' => 'providerDecision(AtlasMemoryEntry $entry)',
            'providerAllowed computes canonical privacy class' => 'privacyClass(data_get($entry->metadata',
            'providerAllowed applies external-ai block list' => 'externalAiAllowed($privacyClass',
            'providerDecision honors explicit metadata block' => 'metadataExternalAiAllowed !== false',
            'providerTitle redacts raw title fallback' => 'AtlasSecurity::redactString((string) $entry->title)',
            'providerSummary redacts raw summary fallback' => 'AtlasSecurity::redactString((string) $entry->summary)',
            'providerBody redacts raw body fallback' => 'AtlasSecurity::redactString((string) $entry->body)',
        ];

        foreach ($checks as $label => $token) {
            if (! str_contains($privacy, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryPrivacyService.php: missing {$label} [{$token}]";
            }
        }

        if (! str_contains($projection, 'providerDecision($entry)')) {
            $violations[] = 'app/Services/Ai/AtlasProviderProjectionService.php: provider projections must filter memory through AtlasMemoryPrivacyService::providerDecision';
        }

        if (! str_contains($projection, 'recordProviderMemoryBlocked($entry')) {
            $violations[] = 'app/Services/Ai/AtlasProviderProjectionService.php: provider projections must record blocked memory decisions to the Evidence Ledger';
        }

        if (! str_contains($openBrain, 'providerAllowed($entry)')) {
            $violations[] = 'app/Services/Ai/AtlasHybridMemoryRetrievalService.php: Open Brain provider context must filter memory through AtlasMemoryPrivacyService::providerAllowed';
        }

        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        if (! str_contains($mcp, 'providerDecision($entry)') || ! str_contains($mcp, "recordProviderMemoryBlocked(\$entry, \$privacyDecision, 'open_brain_mcp'")) {
            $violations[] = 'app/Services/Ai/AtlasOpenBrainMcpService.php: atlas_memory_get must record blocked provider memory decisions to the Evidence Ledger';
        }

        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $ledger = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        if (! str_contains($ledger, 'recordProviderMemoryBlocked(') || ! str_contains($ledger, 'atlas.memory_provider_privacy')) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: missing provider memory privacy block event recorder';
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanMcpDomainCatalogParity(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        $violations = [];

        foreach ([
            "'name' => 'atlas_domain_catalog'",
            "'onboarding_status' => ['type' => 'string'",
            "\$onboardingStatus = \$this->string(\$arguments['onboarding_status'] ?? null)",
            "'invalid_onboarding_status'",
            "'allowed_onboarding_status' => ['ready', 'executable_incomplete', 'scaffold']",
            "'onboarding_status' => \$onboardingStatus",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: atlas_domain_catalog MCP tool must preserve Domain Catalog onboarding parity [{$token}]";
            }
        }

        foreach ([
            'test_domain_catalog_tool_schema_exposes_onboarding_status_filter',
            'test_domain_catalog_tool_filters_by_onboarding_status',
            'test_domain_catalog_tool_rejects_invalid_onboarding_status_filter',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP Domain Catalog onboarding parity needs positive, schema, and invalid-filter coverage [{$token}]";
            }
        }

        if (! str_contains($docs, '`domain`, `flow`, `maturity` e `onboarding_status`')) {
            $violations[] = 'docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md: MCP Domain Catalog docs must mention onboarding_status parity';
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliFixDevRepairAlias(): array
    {
        $fixPath = app_path('Console/Commands/AtlasCliFixCommand.php');
        $builderPath = app_path('Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php');
        $testPath = base_path('tests/Feature/AtlasCliFixCommandTest.php');

        $fix = File::exists($fixPath) ? File::get($fixPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            'AtlasProgrammingSurfaceCommandBuilder',
            "return \$this->call('atlas:cli:dev', \$commands->repairDevArguments(",
            '{--plan-only : Run preflight and print the repair execution plan without calling provider}',
            'planOnly: (bool) $this->option(\'plan-only\')',
        ] as $token) {
            if (! str_contains($fix, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliFixCommand.php: atlas fix must remain a thin atlas dev --repair alias with auditable plan-only support [{$token}]";
            }
        }

        foreach ([
            'public function repairDevArguments(',
            "'--repair' => true",
            "'--plan-only' => \$planOnly",
            "'--permission' => 'write'",
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php: repairDevArguments must route fix through the canonical dev repair contract [{$token}]";
            }
        }

        foreach ([
            'test_fix_plan_only_uses_dev_repair_kernel_flow',
            "'programming.repair'",
            "'atlas_cli_dev'",
            "'dev_execution_plan.kernel_pipeline.provider_execution_allowed'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/AtlasCliFixCommandTest.php: atlas fix needs coverage proving it uses the dev repair kernel flow [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanCliContinueDevResumeAlias(): array
    {
        $continuePath = app_path('Console/Commands/AtlasCliContinueCommand.php');
        $sessionPath = app_path('Services/Ai/Cli/AtlasCliSessionService.php');
        $builderPath = app_path('Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php');
        $testPath = base_path('tests/Feature/AtlasCliContinueCommandTest.php');

        $continue = File::exists($continuePath) ? File::get($continuePath) : '';
        $session = File::exists($sessionPath) ? File::get($sessionPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            'AtlasProgrammingSurfaceCommandBuilder',
            'ProgrammingSurfaceContractFactory',
            '$commands->resumeDevCommand($resume, $operatorOptions, $this->openBrainOptions($operatorOptions))',
            "'resume_contract' => app(ProgrammingSurfaceContractFactory::class)->resume(",
        ] as $token) {
            if (! str_contains($continue, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliContinueCommand.php: atlas continue must remain a thin resume alias into atlas:cli:dev with a structured resume_contract [{$token}]";
            }
        }

        foreach ([
            "'dev_execution_plan'",
            "'programming_session_plan'",
            "'programming_message_plan'",
            'programmingProfileFromPlan(',
            "'operator_options' => is_array(\$plan['operator_options'] ?? null) ? (array) \$plan['operator_options'] : []",
        ] as $token) {
            if (! str_contains($session, $token)) {
                $violations[] = "app/Services/Ai/Cli/AtlasCliSessionService.php: atlas continue must resume canonical dev/programming plans without legacy-only coupling [{$token}]";
            }
        }

        foreach ([
            'public function resumeDevCommand(',
            "'atlas:cli:dev'",
            "'--resume='.(string) \$resume['plan_id']",
            "'--repair'",
            "'--forge'",
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php: resumeDevCommand must translate continue into canonical atlas:cli:dev flags [{$token}]";
            }
        }

        foreach ([
            'test_continue_dry_run_uses_configured_php_binary_for_resume_command',
            'test_continue_resumes_programming_session_plan_without_legacy_dev_plan',
            "'atlas.cli_continue.resume_contract.v1'",
            "'atlas_cli_continue'",
            "'resume_contract.canonical_surface'",
            "'resume_contract.target_surface'",
            'AtlasProgrammingSurfaceCommandBuilder::class',
            "'resume_contract.dev_flags.resume'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/AtlasCliContinueCommandTest.php: atlas continue needs coverage proving resume_contract and canonical dev resume flags [{$token}]";
            }
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
    private function scanCliForgeProgrammingHarnessContract(): array
    {
        $devPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $adapterPath = app_path('Services/Ai/Surface/Adapters/AtlasCliForgeSurfaceAdapter.php');
        $registryPath = app_path('Services/Ai/AtlasDomainProfileRegistry.php');
        $orchestratorPath = app_path('Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $testPath = base_path('tests/Feature/EngineeringHarnessRunnerTest.php');

        $dev = File::exists($devPath) ? File::get($devPath) : '';
        $adapter = File::exists($adapterPath) ? File::get($adapterPath) : '';
        $registry = File::exists($registryPath) ? File::get($registryPath) : '';
        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            "\$programmingProfile === 'forge'",
            'executeWithHarness(ProgrammingExecutionRequest::fromArray',
            "'profile' => 'forge'",
            "'phase' => 'forge_harness'",
            'ProgrammingSurfaceContractFactory',
            "'forge_contract' => app(ProgrammingSurfaceContractFactory::class)->forge(\$devPlan, \$result)",
        ] as $token) {
            if (! str_contains($dev, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliDevCommand.php: atlas forge must remain programming.forge routed through Engineering Harness with a structured forge_contract [{$token}]";
            }
        }

        foreach ([
            "protected const SURFACE_ID = 'atlas_cli_forge'",
            "'default_flow_id' => 'programming.forge'",
            "'prefer_default_flow' => true",
            "'forge' => 'programming.forge'",
            "'heavy' => 'programming.forge'",
        ] as $token) {
            if (! str_contains($adapter, $token)) {
                $violations[] = "app/Services/Ai/Surface/Adapters/AtlasCliForgeSurfaceAdapter.php: forge surface must default to programming.forge [{$token}]";
            }
        }

        foreach ([
            "'programming.forge' => [",
            "'runtime' => 'EngineeringHarness'",
            "'execution_policy' => ['executor_preference' => 'engineering_harness']",
            "'required_bundles' => ['engineering-blueprint', 'dev-quality-gate', 'code-reviewer']",
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/AtlasDomainProfileRegistry.php: programming.forge domain profile must require Engineering Harness and enterprise skill bundles [{$token}]";
            }
        }

        foreach ([
            "'programming.forge'",
            "\$profile = str_ends_with(\$flow, '.forge') || \$flow === 'forge' ? 'forge' : 'dev'",
            'public function execute(array $plan, array $context = []): array',
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php: Programming orchestrator must own programming.forge planning [{$token}]";
            }
        }

        foreach ([
            'test_atlas_cli_forge_routes_to_harness_without_task_id',
            "'atlas.cli_forge.contract.v1'",
            "'forge_contract.surface'",
            "'forge_contract.flow'",
            "'forge_contract.runtime'",
            "'forge_contract.kernel_pipeline_flow'",
            "'forge_contract.evidence_required'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/EngineeringHarnessRunnerTest.php: forge needs coverage proving programming.forge and engineering_harness contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProgrammingSurfaceContractFactory(): array
    {
        $factoryPath = app_path('Services/Ai/Programming/ProgrammingSurfaceContractFactory.php');
        $testPath = base_path('tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php');
        $devPath = app_path('Console/Commands/AtlasCliDevCommand.php');

        $factory = File::exists($factoryPath) ? File::get($factoryPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $dev = File::exists($devPath) ? File::get($devPath) : '';

        $violations = [];

        if (! File::exists($factoryPath)) {
            $violations[] = "missing programming surface contract factory [{$factoryPath}]";
        }

        foreach ([
            'final class ProgrammingSurfaceContractFactory',
            'public function forge(array $devPlan, array $result): array',
            "'schema_version' => 'atlas.cli_forge.contract.v1'",
            "'surface' => 'atlas_cli_forge'",
            "'profile' => 'forge'",
            "'flow' => 'programming.forge'",
            "'runtime' => 'engineering_harness'",
            "'orchestrator' => 'AtlasProgrammingOrchestrator'",
            "'executor' => data_get(\$devPlan, 'programming_session_plan.executor_decision.executor')",
            "'kernel_pipeline_flow' => data_get(\$devPlan, 'kernel_pipeline.input.safe_hints.flow')",
            "'kernel_pipeline_runtime' => data_get(\$devPlan, 'kernel_pipeline.input.safe_hints.runtime')",
            "'quality_required' => true",
            "'evidence_required' => true",
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php: Programming must own the shared forge_contract shape [{$token}]";
            }
        }

        foreach ([
            'test_forge_contract_preserves_programming_surface_flow_runtime_and_evidence_requirements',
            "'atlas.cli_forge.contract.v1'",
            "'atlas_cli_forge'",
            "'programming.forge'",
            "'engineering_harness'",
            "'evidence_required'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php: forge contract factory needs explicit coverage [{$token}]";
            }
        }

        foreach ([
            "'schema_version' => 'atlas.cli_forge.contract.v1'",
            "'surface' => 'atlas_cli_forge'",
            "'kernel_pipeline_flow' => data_get(\$devPlan, 'kernel_pipeline.input.safe_hints.flow')",
        ] as $token) {
            if (str_contains($dev, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliDevCommand.php: surface command must not inline forge_contract schema; use ProgrammingSurfaceContractFactory [{$token}]";
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
    private function scanCliDevModelSelectionContract(): array
    {
        $devPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $testPath = base_path('tests/Feature/AtlasCliDevCommandTest.php');

        $dev = File::exists($devPath) ? File::get($devPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            'ModelSelectionContractFactory',
            "\$preflight['model_selection_contract'] = \$this->modelSelectionContract(",
            "\$devPlan['model_selection_contract'] = \$this->modelSelectionContract(",
            'private function modelSelectionContract(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array',
            '->forCliDev($provider, $modelSelection, $modelOverride, $fairMode, $context)',
        ] as $token) {
            if (! str_contains($dev, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliDevCommand.php: atlas dev must expose model_selection_contract with Atlas Decide authority [{$token}]";
            }
        }

        foreach ([
            "'workflow.model_selection_contract.schema_version'",
            "'workflow.model_selection_contract.authority'",
            "'workflow.model_selection_contract.selection_mode'",
            "'workflow.model_selection_contract.available_selection_modes'",
            "'workflow.model_selection_contract.operator_requested_provider'",
            "'workflow.model_selection_contract.requested_model'",
            "'workflow.model_selection_contract.requested_model_alias'",
            "'dev_execution_plan.model_selection_contract'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/AtlasCliDevCommandTest.php: atlas dev needs tests for model selection contract in auto and manual override paths [{$token}]";
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
    private function scanKernelModelSelectionContractFactory(): array
    {
        $factoryPath = app_path('Services/Ai/Kernel/Decision/ModelSelectionContractFactory.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/ModelSelectionContractFactoryTest.php');
        $devPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $chatPath = app_path('Console/Commands/AiChatCommand.php');

        $factory = File::exists($factoryPath) ? File::get($factoryPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $dev = File::exists($devPath) ? File::get($devPath) : '';
        $chat = File::exists($chatPath) ? File::get($chatPath) : '';

        $violations = [];

        if (! File::exists($factoryPath)) {
            $violations[] = "missing model selection contract factory [{$factoryPath}]";
        }

        foreach ([
            'final class ModelSelectionContractFactory',
            "public const AUTHORITY = 'atlas_decide'",
            'public const AVAILABLE_SELECTION_MODES = [',
            'public function forCliDev(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array',
            'public function forAiChat(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array',
            "'schema_version' => \$schemaVersion",
            "'surface' => \$surface",
            "'authority' => self::AUTHORITY",
            "'selection_mode' => \$manual ? 'manual_override' : 'auto_best_allowed'",
            "'available_selection_modes' => self::AVAILABLE_SELECTION_MODES",
            "'operator_requested_provider' => \$provider ?: 'auto'",
            "'requested_model' => \$modelOverride",
            "'specialist_profile' => \$specialistProfile",
            'specialistProfile(array $context)',
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/ModelSelectionContractFactory.php: kernel must own the shared model selection contract shape [{$token}]";
            }
        }

        foreach ([
            'test_cli_dev_contract_defaults_to_auto_best_allowed_under_decide_authority',
            'test_ai_chat_contract_preserves_manual_provider_model_and_alias',
            'test_contract_carries_specialist_profile_without_changing_decide_authority',
            'test_fair_mode_is_audited_as_manual_override_without_provider',
            "'atlas.cli_dev.model_selection_contract.v1'",
            "'atlas.ai_chat.model_selection_contract.v1'",
            "'atlas_decide'",
            "'auto_best_allowed'",
            "'manual_override'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/ModelSelectionContractFactoryTest.php: shared model selection contract factory needs explicit coverage [{$token}]";
            }
        }

        foreach (["'schema_version' => 'atlas.cli_dev.model_selection_contract.v1'", "'schema_version' => 'atlas.ai_chat.model_selection_contract.v1'"] as $token) {
            if (str_contains($dev, $token) || str_contains($chat, $token)) {
                $violations[] = "surface commands must not inline model selection contract schema; use ModelSelectionContractFactory [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptHashRuntimeGuard(): array
    {
        $guardPath = app_path('Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php');
        $hashPath = app_path('Services/Ai/Kernel/Decision/DecisionReceiptHash.php');
        $issuerPath = app_path('Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php');
        $workerPath = app_path('Services/Ai/AiWorker.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/DecisionReceiptRuntimeGuardTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-134-decision-receipt-hash-runtime-guard.md');

        $guard = File::exists($guardPath) ? File::get($guardPath) : '';
        $hash = File::exists($hashPath) ? File::get($hashPath) : '';
        $issuer = File::exists($issuerPath) ? File::get($issuerPath) : '';
        $worker = File::exists($workerPath) ? File::get($workerPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'final class DecisionReceiptHash',
            'public static function hash(array $payload): string',
            'public static function canonicalize(array $payload): array',
            'JSON_THROW_ON_ERROR',
        ] as $token) {
            if (! str_contains($hash, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/DecisionReceiptHash.php: AP-134 shared receipt hasher must exist [{$token}]";
            }
        }

        foreach ([
            'DecisionReceiptHash::hash($payload)',
            "'envelope_input_hash'",
            "'parent_chain_hash'",
        ] as $token) {
            if (! str_contains($issuer, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php: AP-134 issuer must use shared hash contract and persist verification hints [{$token}]";
            }
        }

        foreach ([
            'private function hashIntegrityViolation(array $receiptV2, array $base): ?DecisionReceiptRuntimeViolation',
            'decision_receipt_hash_mismatch',
            'DecisionReceiptHash::hash([',
            'inputs_hash',
            'receipt_hash',
            'chain_hash',
            'hash_equals(',
        ] as $token) {
            if (! str_contains($guard, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php: AP-134 runtime guard must reject tampered DecisionReceipt hashes [{$token}]";
            }
        }

        if (! str_contains($worker, "'decision_receipt_hash_mismatch'")) {
            $violations[] = 'app/Services/Ai/AiWorker.php: AP-134 worker must treat hash mismatch as pre-provider block [decision_receipt_hash_mismatch]';
        }

        foreach ([
            'test_accepts_issued_receipt_with_matching_hashes',
            'test_blocks_issued_receipt_when_signed_payload_is_tampered',
            'decision_receipt_hash_mismatch',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/DecisionReceiptRuntimeGuardTest.php: AP-134 hash runtime guard must be unit tested [{$token}]";
            }
        }

        foreach ([
            'AP-134',
            'Decision Receipt Hash Runtime Guard',
            'decision_receipt_hash_mismatch',
            'ap134_decision_receipt_hash_runtime_guard',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-134 receipt hash runtime guard must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-134-decision-receipt-hash-runtime-guard.md: AP-134 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptDeterminismTest(): array
    {
        $testPath = base_path('tests/Feature/Architecture/DecisionReceiptDeterminismTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-135-decision-receipt-determinism-test.md');

        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'class DecisionReceiptDeterminismTest extends TestCase',
            'test_receipt_hashes_are_stable_for_same_envelope_and_decision_contract',
            'test_receipt_hash_changes_when_authorized_provider_changes',
            'test_runtime_guard_rejects_replayed_receipt_after_signed_payload_mutation',
            'DecisionReceiptIssuer',
            'DecisionReceiptRuntimeGuard',
            'decision_receipt_hash_mismatch',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Architecture/DecisionReceiptDeterminismTest.php: AP-135 must prove DecisionReceipt determinism and tamper rejection [{$token}]";
            }
        }

        foreach ([
            'AP-135',
            'Decision Receipt Determinism Test',
            'DecisionReceiptDeterminismTest',
            'ap135_decision_receipt_determinism_test',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-135 DecisionReceipt determinism test must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-135-decision-receipt-determinism-test.md: AP-135 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptChainReplay(): array
    {
        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-136-decision-receipt-chain-replay.md');

        $ledger = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            "'parent_receipt_id' => \$receipt['parent_receipt_id'] ?? null",
            "'parent_chain_hash' => \$receipt['parent_chain_hash'] ?? data_get(\$receipt, 'metadata.parent_chain_hash')",
        ] as $token) {
            if (! str_contains($ledger, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: AP-136 DECISION_ISSUED must preserve receipt chain fields [{$token}]";
            }
        }

        foreach ([
            'public function decisionReceiptReportForEnvelope(string $envelopeId): array',
            'private function decisionReceiptEventFromEvent(array $event): array',
            'private function decisionReceiptEventSummary(Collection $events): array',
            'private function decisionReceiptReviewSignal(Collection $events, Collection $invalidEvents): array',
            'DecisionReceiptHash::hash([',
            'decision_receipt_chain_hash_mismatch',
            'open_reviewable_decision_receipt_replay_proposal',
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-136 replay must verify DecisionReceipt chain integrity [{$token}]";
            }
        }

        foreach ([
            'test_decision_receipt_report_projects_chain_integrity_for_envelope',
            'test_decision_receipt_report_flags_hash_mismatch_for_review',
            'recordDecisionReceiptEvent',
            'decisionReceiptReportForEnvelope',
            'decision_receipt_chain_hash_mismatch',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-136 DecisionReceipt chain replay must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-136',
            'Decision Receipt Chain Replay',
            'decisionReceiptReportForEnvelope',
            'ap136_decision_receipt_chain_replay',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-136 DecisionReceipt chain replay must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-136-decision-receipt-chain-replay.md: AP-136 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptReplaySurfaces(): array
    {
        $commandPath = app_path('Console/Commands/AtlasAiDecisionReceiptReportCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiDecisionReceiptReportController.php');
        $routesPath = base_path('routes/api.php');
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiDecisionReceiptReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiDecisionReceiptReportApiTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-137-decision-receipt-replay-surfaces.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            "protected \$signature = 'atlas:ai:decision-receipt-report",
            'decisionReceiptReportForEnvelope($envelopeId)',
            "'decision_receipt_replay' => \$report",
            'envelope_required',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiDecisionReceiptReportCommand.php: AP-137 CLI surface must expose DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiDecisionReceiptReportController',
            "'envelope' => ['required', 'string', 'max:160']",
            'decisionReceiptReportForEnvelope($data',
            "'decision_receipt_replay'",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiDecisionReceiptReportController.php: AP-137 API surface must expose DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'AtlasAiDecisionReceiptReportController::class',
            '/ai/decision-receipts/report',
        ] as $token) {
            if (! str_contains($routes, $token)) {
                $violations[] = "routes/api.php: AP-137 API route must be registered [{$token}]";
            }
        }

        foreach ([
            "'name' => 'atlas_decision_receipt_report'",
            "'required' => ['envelope']",
            "'atlas_decision_receipt_report' => \$this->toolResponse(\$id, \$this->decisionReceiptReport(\$arguments))",
            'private function decisionReceiptReport(array $arguments): array',
            "'decision_receipt_replay' => \$this->ledgerReplay->decisionReceiptReportForEnvelope(\$envelopeId)",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-137 MCP tool must expose DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            "'id' => 'decision_receipt_report'",
            'atlas ai decision-receipt-report --envelope=<id> --json',
            'DecisionReceipt',
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-137 architecture operations catalog must include DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'test_command_replays_decision_receipt_chain_as_json',
            'test_command_reports_invalid_input_without_envelope',
            'atlas:ai:decision-receipt-report',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiDecisionReceiptReportCommandTest.php: AP-137 CLI tests must cover DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'test_api_replays_decision_receipt_chain_for_envelope',
            'test_api_requires_atlas_token',
            '/ai/decision-receipts/report?envelope=env_api_receipt',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiDecisionReceiptReportApiTest.php: AP-137 API tests must cover DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'test_decision_receipt_report_replays_receipt_chain_for_envelope',
            'test_decision_receipt_report_requires_envelope',
            'atlas_decision_receipt_report',
            'recordDecisionReceiptForMcp',
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-137 MCP tests must cover DecisionReceipt replay [{$token}]";
            }
        }

        foreach ([
            'AP-137',
            'Decision Receipt Replay Surfaces',
            'atlas:ai:decision-receipt-report',
            'ap137_decision_receipt_replay_surfaces',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-137 DecisionReceipt replay surfaces must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-137-decision-receipt-replay-surfaces.md: AP-137 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptReplayCuratorReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-138-decision-receipt-replay-curator-review.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'decisionReceiptReplayFindings($events, $filters)',
            'private function decisionReceiptReplayFindings(Collection $events, array $filters = []): array',
            'decisionReceiptReportForEnvelope($envelopeId)',
            'open_reviewable_decision_receipt_replay_proposal',
            'atlas.self_improvement.decision_receipt_replay_gap.v1',
            'matchesDecisionReceiptFilters',
            'normalizedDecisionReceiptFilters',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-138 Curator must consume DecisionReceipt replay findings [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_decision_receipt_replay_hash_gap',
            'recordDecisionReceiptEvent',
            'atlas.self_improvement.decision_receipt_replay_gap.v1',
            'decision_receipt_chain_hash_mismatch',
            'open_reviewable_decision_receipt_replay_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-138 Curator DecisionReceipt replay must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-138',
            'Decision Receipt Replay Curator Review',
            'decisionReceiptReplayFindings',
            'ap138_decision_receipt_replay_curator_review',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-138 DecisionReceipt replay Curator review must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-138-decision-receipt-replay-curator-review.md: AP-138 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDecisionReceiptReplayInboxEmission(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-139-decision-receipt-replay-inbox-emission.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            '$this->proposals->emit',
            'emitted_to_inbox',
            'emitted_inbox_item_id',
            'LedgerEventType::LearningProposed',
            'emitted_inbox_item_ids',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-139 DecisionReceipt replay findings must flow through proposal emission [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_emits_decision_receipt_replay_hash_gap_proposal',
            'self-improvement:decision-receipt-replay:',
            'atlas.self_improvement.decision_receipt_replay_gap.v1',
            'open_reviewable_decision_receipt_replay_proposal',
            'emitted_to_inbox',
            'emitted_inbox_item_id',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-139 DecisionReceipt replay proposal emission must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-139',
            'Decision Receipt Replay Inbox Emission',
            'test_self_improvement_emits_decision_receipt_replay_hash_gap_proposal',
            'ap139_decision_receipt_replay_inbox_emission',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-139 DecisionReceipt replay Inbox emission must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-139-decision-receipt-replay-inbox-emission.md: AP-139 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerReplayCommandSurface(): array
    {
        $commandPath = app_path('Console/Commands/AtlasLedgerReplayCommand.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasLedgerReplayCommandTest.php');
        $catalogTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-140-ledger-replay-command-surface.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $catalogTest = File::exists($catalogTestPath) ? File::get($catalogTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            "protected \$signature = 'atlas:ledger:replay",
            'KernelLedgerEnvelopeReportService',
            '{--envelope=',
            'envelope_required',
            'includeSlo',
            'includeRepair',
            'includeKernel',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasLedgerReplayCommand.php: AP-140 ledger replay command must expose canonical envelope replay [{$token}]";
            }
        }

        foreach ([
            "'id' => 'ledger_replay'",
            "'command' => 'atlas ledger replay --envelope=<id> --json'",
            "'kind' => 'evidence_report'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-140 ledger replay must be discoverable [{$token}]";
            }
        }

        if (! str_contains($runtime, 'atlas ledger replay --envelope=<id> --json')) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-140 Self-Improvement must protect ledger replay in architecture operations expected commands';
        }

        foreach ([
            'test_command_replays_envelope_from_named_option_as_json',
            'test_command_requires_envelope_option',
            "Artisan::call('atlas:ledger:replay'",
            'envelope_required',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasLedgerReplayCommandTest.php: AP-140 command behavior must be tested [{$token}]";
            }
        }

        foreach ([
            'ledger_replay',
            'atlas ledger replay --envelope=<id> --json',
        ] as $token) {
            if (! str_contains($catalogTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-140 catalog discovery must be tested [{$token}]";
            }
        }

        foreach ([
            'AP-140',
            'Ledger Replay Command Surface',
            'atlas:ledger:replay',
            'ap140_ledger_replay_command_surface',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-140 ledger replay command must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-140-ledger-replay-command-surface.md: AP-140 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerProjectionRegistryContract(): array
    {
        $registryPath = app_path('Services/Ai/Kernel/Evidence/LedgerProjectionRegistry.php');
        $validationPath = app_path('Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerProjectionRegistryTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-141-ledger-projection-registry-contract.md');

        $registry = File::exists($registryPath) ? File::get($registryPath) : '';
        $validation = File::exists($validationPath) ? File::get($validationPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'final class LedgerProjectionRegistry',
            "'schema_version' => 'atlas.ledger_projection_registry.v1'",
            "'id' => 'ai_traces'",
            "'id' => 'atlas_engineering_runs'",
            "'id' => 'atlas_tool_runs'",
            'LedgerEventType::ProviderCalled',
            'LedgerEventType::ToolEvidenceRecorded',
            'LedgerEventType::RepairCompleted',
            'required_columns',
            'identity_keys',
            'readinessWarnings',
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/LedgerProjectionRegistry.php: AP-141 ledger projection registry contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'LedgerProjectionRegistry',
            'ledger_projections',
            "'schema_version' => \$ledgerProjectionReport['schema_version']",
            "'projection_ids' => \$ledgerProjectionReport['projection_ids']",
        ] as $token) {
            if (! str_contains($validation, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php: AP-141 architecture validate must expose ledger projections [{$token}]";
            }
        }

        foreach ([
            'test_registry_declares_core_ledger_projections',
            'test_registry_reports_projection_readiness_from_schema',
            'test_registry_reports_missing_table_without_column_noise',
            'atlas.ledger_projection_registry.v1',
            'atlas_engineering_runs',
            'atlas_tool_runs',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerProjectionRegistryTest.php: AP-141 projection registry must be unit tested [{$token}]";
            }
        }

        foreach ([
            'kernel.ledger_projections.valid',
            'kernel.ledger_projections.schema_version',
            'ap141_ledger_projection_registry_contract',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: AP-141 command validate payload must be covered [{$token}]";
            }
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php: AP-141 API validate payload must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-141',
            'Ledger Projection Registry Contract',
            'LedgerProjectionRegistry',
            'ap141_ledger_projection_registry_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-141 ledger projection registry must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-141-ledger-projection-registry-contract.md: AP-141 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerProjectionInboxAction(): array
    {
        $registryPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $cliPath = app_path('Console/Commands/AtlasCliInboxCommand.php');
        $testPath = base_path('tests/Feature/Ai/InboxLedgerProjectionActionTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-142-ledger-projection-inbox-action.md');

        $registry = File::exists($registryPath) ? File::get($registryPath) : '';
        $cli = File::exists($cliPath) ? File::get($cliPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'LedgerProjectionWorker',
            "'run_ledger_projection' => \$this->runLedgerProjection(\$locked, \$input)",
            'private function runLedgerProjection(AiInboxItem $item, array $input): array',
            "'schema_version' => 'atlas.inbox_action.ledger_projection.v1'",
            "'ledger_projection_action' => \$payload['ledger_projection_action']",
            "'status' => \$applied ? 'resolved'",
            'LedgerEventType::InboxActionRecorded',
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-142 ledger projection Inbox action is incomplete [{$token}]";
            }
        }

        foreach ([
            '{--projection-hours= : Hours window for run_ledger_projection}',
            '{--projection-limit= : Max ledger events for run_ledger_projection}',
            '{--dry-run : Preview run_ledger_projection without writing projection tables}',
            "'projection_hours' => \$this->option('projection-hours')",
            "'projection_limit' => \$this->option('projection-limit')",
        ] as $token) {
            if (! str_contains($cli, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliInboxCommand.php: AP-142 CLI must expose projection action inputs [{$token}]";
            }
        }

        foreach ([
            'class InboxLedgerProjectionActionTest',
            'test_inbox_action_runs_ledger_projection_and_records_reviewable_evidence',
            'test_inbox_action_can_preview_ledger_projection_without_resolving_item',
            'run_ledger_projection',
            'atlas.inbox_action.ledger_projection.v1',
            'LedgerEventType::InboxActionRecorded',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/InboxLedgerProjectionActionTest.php: AP-142 Inbox projection action must be tested [{$token}]";
            }
        }

        foreach ([
            'AP-142',
            'Ledger Projection Inbox Action',
            'run_ledger_projection',
            'atlas.inbox_action.ledger_projection.v1',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-142 ledger projection Inbox action must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-142-ledger-projection-inbox-action.md: AP-142 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLedgerProjectionCuratorActionEmission(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $emitterPath = app_path('Services/Ai/Mobile/ProposalInboxEmitter.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $emitterTestPath = base_path('tests/Unit/Ai/ProposalInboxEmitterTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-143-ledger-projection-curator-action-emission.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $emitter = File::exists($emitterPath) ? File::get($emitterPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $emitterTest = File::exists($emitterTestPath) ? File::get($emitterTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            'ledgerProjectionDriftFindings',
            "'available_actions' => [",
            "'id' => 'run_ledger_projection'",
            "'projection_health' => [",
            "'ledger_projection' => [",
            "'recommended_action' => 'open_reviewable_ledger_projection_backfill_proposal'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-143 Curator must emit actionable ledger projection proposal [{$token}]";
            }
        }

        foreach ([
            '$availableActions = $this->availableActions($data)',
            'private function availableActions(array $data): array',
            "\$this->array(\$data['available_actions'] ?? [])",
            "\$payload = \$this->array(\$data['payload'] ?? [])",
            'array_replace_recursive($payload',
        ] as $token) {
            if (! str_contains($emitter, $token)) {
                $violations[] = "app/Services/Ai/Mobile/ProposalInboxEmitter.php: AP-143 Proposal emitter must preserve custom actions and payload [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_emits_ledger_projection_drift_proposal_with_assisted_action',
            'run_ledger_projection',
            'payload.projection_health.status',
            'payload.ledger_projection.hours',
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-143 Curator emission must be feature tested [{$token}]";
            }
        }

        foreach ([
            'test_proposal_preserves_custom_actions_and_payload_for_assisted_operations',
            'run_ledger_projection',
            'available_actions.0.id',
            'raw_payload.projection_health.status',
        ] as $token) {
            if (! str_contains($emitterTest, $token)) {
                $violations[] = "tests/Unit/Ai/ProposalInboxEmitterTest.php: AP-143 emitter payload/action preservation must be unit tested [{$token}]";
            }
        }

        foreach ([
            'AP-143',
            'Ledger Projection Curator Action Emission',
            'run_ledger_projection',
            'available_actions',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-143 Curator action emission must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-143-ledger-projection-curator-action-emission.md: AP-143 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRivalsReviewInboxActionContract(): array
    {
        $actionsPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $inboxActionTestPath = base_path('tests/Feature/Ai/InboxLedgerProjectionActionTest.php');
        $replayTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiInboxActionReportApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-144-rivals-review-inbox-action-contract.md');

        $actions = File::exists($actionsPath) ? File::get($actionsPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $inboxActionTest = File::exists($inboxActionTestPath) ? File::get($inboxActionTestPath) : '';
        $replayTest = File::exists($replayTestPath) ? File::get($replayTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        $violations = [];

        foreach ([
            "'record_rivals_review' => \$this->recordRivalsReview(\$locked, \$input)",
            'private readonly AtlasRivalsStrategyReviewRecorder $rivalsStrategyReviewRecorder',
            'private readonly AtlasRivalsStrategyReadModel $rivalsStrategy',
            'private function recordRivalsReview(AiInboxItem $item, array $input): array',
            "'schema_version' => 'atlas.inbox_action.rivals_review.v1'",
            "'operator_scored' => true",
            "'no_external_action' => true",
            '$this->rivalsStrategyReviewRecorder->record',
        ] as $token) {
            if (! str_contains($actions, $token)) {
                $violations[] = "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-144 Rivals review Inbox action must record human scores safely [{$token}]";
            }
        }

        foreach ([
            "'id' => 'record_rivals_review'",
            "'payload' => [",
            "'due_reviews' =>",
            "'record_command_template' => 'atlas ai rivals-strategy record-review --review-id=<id> --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json'",
            "'rivals_strategy_due_review'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-144 Curator must emit actionable Rivals review proposals [{$token}]";
            }
        }

        foreach ([
            "'rivals_review_schema_version' => data_get(\$result, 'rivals_review_action.schema_version')",
            "'rivals_review_id' => data_get(\$result, 'rivals_review_action.recorded_review_id')",
            "'rivals_regret_score' => data_get(\$result, 'rivals_review_action.scores.regret')",
            "'rivals_alignment_score' => data_get(\$result, 'rivals_review_action.scores.alignment')",
            "'rivals_agency_score' => data_get(\$result, 'rivals_review_action.scores.agency')",
            "'rivals_review_recorded_count' => \$rivalsReviewRecordedCount",
            "'rivals_strategy_human_scores_recorded'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-144 Rivals review action must be projected from Evidence Ledger [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_records_rivals_review_with_human_scores_and_ledger_evidence',
            'record_rivals_review',
            'atlas.inbox_action.rivals_review.v1',
            'LedgerEventType::InboxActionRecorded',
        ] as $token) {
            if (! str_contains($inboxActionTest, $token)) {
                $violations[] = "tests/Feature/Ai/InboxLedgerProjectionActionTest.php: AP-144 Inbox action execution must be tested [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_window_report_projects_rivals_review_scores',
            'rivals_review_recorded_count',
            'rivals_review_with_scores_count',
            'rivals_strategy_human_scores_recorded',
        ] as $token) {
            if (! str_contains($replayTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-144 Ledger replay projection must be tested [{$token}]";
            }
        }

        foreach ([
            'test_command_exposes_rivals_review_scores_as_json',
            'record_rivals_review',
            'rivals_review_with_scores_count',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php: AP-144 CLI report must expose Rivals review scores [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_report_api_exposes_rivals_review_scores',
            'record_rivals_review',
            'rivals_review_with_scores_count',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiInboxActionReportApiTest.php: AP-144 API report must expose Rivals review scores [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_exposes_rivals_review_inbox_action_scores',
            'record_rivals_review',
            'rivals_review_with_scores_count',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-144 Observability must expose Rivals review scores [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_report_tool_exposes_rivals_review_scores',
            'record_rivals_review',
            'rivals_review_with_scores_count',
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-144 MCP report must expose Rivals review scores [{$token}]";
            }
        }

        foreach ([
            'AP-144',
            'Rivals Review Inbox Action Contract',
            'record_rivals_review',
            'atlas.inbox_action.rivals_review.v1',
            'rivals_strategy_human_scores_recorded',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-144 Rivals review Inbox action must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-144-rivals-review-inbox-action-contract.md: AP-144 contract doc must exist [{$token}]";
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
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
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
    private function scanAgentBehaviorIdentityFragment(): array
    {
        $contractPath = app_path('Services/Ai/Kernel/Provider/AgentBehaviorContract.php');
        $projectorPath = app_path('Services/Ai/Kernel/Provider/AtlasProviderIdentityProjector.php');
        $providerTestPath = base_path('tests/Unit/Ai/Provider/ProviderDriverWrappersTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-148-agent-behavior-identity-fragment.md');

        $contract = File::exists($contractPath) ? File::get($contractPath) : '';
        $projector = File::exists($projectorPath) ? File::get($projectorPath) : '';
        $providerTest = File::exists($providerTestPath) ? File::get($providerTestPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'final readonly class AgentBehaviorContract',
            "public const CONTRACT_ID = 'atlas-ai.agent-behavior.v1'",
            'Assumption Management',
            'Simplicity Bias',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
            'contentHash()',
        ] as $token) {
            if (! str_contains($contract, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Provider/AgentBehaviorContract.php: AP-148 behavior contract must be centralized and hashable [{$token}]";
            }
        }

        foreach ([
            'private readonly AgentBehaviorContract $agentBehavior',
            "'agent_behavior_contract' => \$this->agentBehavior->toArray()",
            'Behavior contract:',
            '$this->agentBehavior->text()',
        ] as $token) {
            if (! str_contains($projector, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Provider/AtlasProviderIdentityProjector.php: AP-148 behavior contract must be injected through IdentityFragment [{$token}]";
            }
        }

        foreach ([
            'Atlas AI Agent Behavior Contract v1',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
            'agent_behavior_contract.contract_id',
            'atlas-ai.agent-behavior.v1',
        ] as $token) {
            if (! str_contains($providerTest, $token)) {
                $violations[] = "tests/Unit/Ai/Provider/ProviderDriverWrappersTest.php: AP-148 provider tests must prove behavior contract injection [{$token}]";
            }
        }

        foreach ([
            'ABC-1',
            'implemented',
            'AgentBehaviorContract',
            'atlas-ai.agent-behavior.v1',
            'AP-148',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-148 must be reflected in the behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-148',
            'implemented-identity-contract',
            'AgentBehaviorContract',
            'AtlasProviderIdentityProjector',
            'identity_fragment.metadata.agent_behavior_contract',
            'ProviderDriverWrappersTest::test_prepare_request_injects_identity_fragment_into_payload',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-148-agent-behavior-identity-fragment.md: AP-148 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorExecutionPlan(): array
    {
        $executionPlanPath = app_path('Services/Ai/ValueObjects/AiExecutionPlan.php');
        $harnessTestPath = base_path('tests/Unit/AiHarnessContractsTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-149-agent-behavior-execution-plan.md');

        $executionPlan = File::exists($executionPlanPath) ? File::get($executionPlanPath) : '';
        $harnessTest = File::exists($harnessTestPath) ? File::get($harnessTestPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'use App\Services\Ai\Kernel\Provider\AgentBehaviorContract',
            '$agentBehavior = app(AgentBehaviorContract::class)',
            "'agent_behavior_contract' => \$agentBehavior->toArray()",
            '## Agent Behavior Contract',
            "'principles'",
        ] as $token) {
            if (! str_contains($executionPlan, $token)) {
                $violations[] = "app/Services/Ai/ValueObjects/AiExecutionPlan.php: AP-149 execution plan must carry agent behavior contract [{$token}]";
            }
        }

        foreach ([
            'test_execution_plan_prompt_renders_agent_behavior_contract',
            'agent_behavior_contract.contract_id',
            'atlas-ai.agent-behavior.v1',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
        ] as $token) {
            if (! str_contains($harnessTest, $token)) {
                $violations[] = "tests/Unit/AiHarnessContractsTest.php: AP-149 execution plan behavior contract must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-2',
            'implemented-initial',
            'AiExecutionPlan',
            'agent_behavior_contract',
            'AP-149',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-149 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-149',
            'implemented-initial-contract',
            'AiExecutionPlan::fromTask',
            'agent_behavior_contract',
            'KernelArchitectureStaticScanner::scanAgentBehaviorExecutionPlan',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-149-agent-behavior-execution-plan.md: AP-149 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorQualityGate(): array
    {
        $gatePath = app_path('Services/Ai/Kernel/Behavior/AgentBehaviorQualityGate.php');
        $evaluatorPath = app_path('Services/Ai/AiQualityEvaluator.php');
        $gateTestPath = base_path('tests/Unit/Ai/Kernel/Behavior/AgentBehaviorQualityGateTest.php');
        $qualityTestPath = base_path('tests/Unit/AiQualityEvaluatorTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-150-agent-behavior-quality-gate.md');

        $gate = File::exists($gatePath) ? File::get($gatePath) : '';
        $evaluator = File::exists($evaluatorPath) ? File::get($evaluatorPath) : '';
        $gateTest = File::exists($gateTestPath) ? File::get($gateTestPath) : '';
        $qualityTest = File::exists($qualityTestPath) ? File::get($qualityTestPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'final readonly class AgentBehaviorQualityGate',
            'AgentBehaviorContract',
            'agent.verification_missing',
            'agent.unsurgical_diff',
            'atlas.agent_behavior.finding.v1',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
            'contract_hash',
        ] as $token) {
            if (! str_contains($gate, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Behavior/AgentBehaviorQualityGate.php: AP-150 behavior findings must be centralized and contract-hashable [{$token}]";
            }
        }

        foreach ([
            'private readonly AgentBehaviorQualityGate $agentBehaviorGate',
            '$agentBehaviorFindings = $this->agentBehaviorGate->evaluate',
            'agent_behavior_findings',
            'verification_missing',
        ] as $token) {
            if (! str_contains($evaluator, $token)) {
                $violations[] = "app/Services/Ai/AiQualityEvaluator.php: AP-150 quality evaluator must consume behavior gate findings [{$token}]";
            }
        }

        foreach ([
            'AgentBehaviorQualityGateTest',
            'test_emits_verification_missing_finding_for_development_without_verification_signal',
            'test_emits_unsurgical_diff_finding_when_changed_file_is_outside_allowed_paths',
            'atlas.agent_behavior.finding.v1',
            'agent.unsurgical_diff',
        ] as $token) {
            if (! str_contains($gateTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Behavior/AgentBehaviorQualityGateTest.php: AP-150 behavior gate must be covered [{$token}]";
            }
        }

        foreach ([
            'agent_behavior_findings.0.code',
            'agent.verification_missing',
        ] as $token) {
            if (! str_contains($qualityTest, $token)) {
                $violations[] = "tests/Unit/AiQualityEvaluatorTest.php: AP-150 evaluator integration must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-3',
            'implemented-initial',
            'AgentBehaviorQualityGate',
            'agent.verification_missing',
            'agent.unsurgical_diff',
            'AP-150',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-150 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-150',
            'implemented-initial-gate',
            'AgentBehaviorQualityGate',
            'agent.verification_missing',
            'agent.unsurgical_diff',
            'KernelArchitectureStaticScanner::scanAgentBehaviorQualityGate',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-150-agent-behavior-quality-gate.md: AP-150 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorReviewActionSurface(): array
    {
        $actionServicePath = app_path('Services/Ai/AiQualityActionService.php');
        $actionTestPath = base_path('tests/Unit/AiQualityActionServiceTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-151-agent-behavior-review-action-surface.md');

        $actionService = File::exists($actionServicePath) ? File::get($actionServicePath) : '';
        $actionTest = File::exists($actionTestPath) ? File::get($actionTestPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            "'agent_behavior_findings' => \$this->agentBehaviorFindings(\$evaluation)",
            'private function agentBehaviorFindings(AiQualityEvaluation $evaluation): array',
            'evidence.agent_behavior_findings',
            "str_starts_with((string) (\$finding['code'] ?? ''), 'agent.')",
        ] as $token) {
            if (! str_contains($actionService, $token)) {
                $violations[] = "app/Services/Ai/AiQualityActionService.php: AP-151 review actions must carry structured agent behavior findings [{$token}]";
            }
        }

        foreach ([
            'test_verification_action_carries_agent_behavior_findings_for_review',
            'agent_behavior_findings.0.code',
            'agent.verification_missing',
            'atlas.agent_behavior.finding.v1',
        ] as $token) {
            if (! str_contains($actionTest, $token)) {
                $violations[] = "tests/Unit/AiQualityActionServiceTest.php: AP-151 review action payload must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-4',
            'implemented-initial',
            'AiQualityActionService',
            'agent_behavior_findings',
            'AP-151',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-151 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-151',
            'implemented-initial-review-surface',
            'AiQualityActionService',
            'agent_behavior_findings',
            'KernelArchitectureStaticScanner::scanAgentBehaviorReviewActionSurface',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-151-agent-behavior-review-action-surface.md: AP-151 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProgrammingPlanAgentBehaviorContract(): array
    {
        $orchestratorPath = app_path('Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $testPath = base_path('tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $programmingDocPath = base_path('docs/engineering-knowledge-base/domains/programming.md');
        $apDocPath = base_path('docs/ap/AP-152-programming-plan-agent-behavior-contract.md');

        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $programmingDoc = File::exists($programmingDocPath) ? File::get($programmingDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'use App\Services\Ai\Kernel\Provider\AgentBehaviorContract',
            'private readonly AgentBehaviorContract $agentBehavior',
            "'agent_behavior_contract' => \$this->agentBehavior->toArray()",
            'repair_execution_contract',
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php: AP-152 Programming plan must carry behavior contract [{$token}]";
            }
        }

        foreach ([
            'agent_behavior_contract.contract_id',
            'atlas-ai.agent-behavior.v1',
            'Surgical Diff Discipline',
            'agent_behavior_contract.content_hash',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php: AP-152 Programming plan behavior contract must be tested [{$token}]";
            }
        }

        foreach ([
            'ABC-5',
            'implemented',
            'AtlasProgrammingOrchestrator',
            'agent_behavior_contract',
            'AP-152',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-152 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'agent_behavior_contract',
            'AP-152',
            'atlas-ai.agent-behavior.v1',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
        ] as $token) {
            if (! str_contains($programmingDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/domains/programming.md: AP-152 must be documented in Programming Domain [{$token}]";
            }
        }

        foreach ([
            'AP-152',
            'implemented-programming-plan-contract',
            'AtlasProgrammingOrchestrator::sessionPlan',
            'agent_behavior_contract',
            'KernelArchitectureStaticScanner::scanProgrammingPlanAgentBehaviorContract',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-152-programming-plan-agent-behavior-contract.md: AP-152 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProgrammingHarnessAgentBehaviorContract(): array
    {
        $requestPath = app_path('Services/Ai/Programming/ProgrammingExecutionRequest.php');
        $harnessPath = app_path('Services/Engineering/EngineeringHarnessExecutionService.php');
        $testPath = base_path('tests/Feature/EngineeringHarnessRunnerTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $programmingDocPath = base_path('docs/engineering-knowledge-base/domains/programming.md');
        $apDocPath = base_path('docs/ap/AP-153-programming-harness-agent-behavior-contract.md');

        $request = File::exists($requestPath) ? File::get($requestPath) : '';
        $harness = File::exists($harnessPath) ? File::get($harnessPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $programmingDoc = File::exists($programmingDocPath) ? File::get($programmingDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'use App\Services\Ai\Kernel\Provider\AgentBehaviorContract',
            'public function agentBehaviorContract(): array',
            'programming_message_plan.agent_behavior_contract',
            'dev_execution_plan.programming_session_plan.agent_behavior_contract',
            "'agent_behavior_contract' => \$this->agentBehaviorContract()",
        ] as $token) {
            if (! str_contains($request, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingExecutionRequest.php: AP-153 request must expose harness behavior contract [{$token}]";
            }
        }

        foreach ([
            "'agent_behavior_contract' => \$request->agentBehaviorContract()",
            "'agent_behavior_contract' => \$options['agent_behavior_contract'] ?? null",
            "'agent_behavior_contract' => \$request->agentBehaviorContract()",
            'policy_contract_enforcement',
        ] as $token) {
            if (! str_contains($harness, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessExecutionService.php: AP-153 harness must propagate behavior contract [{$token}]";
            }
        }

        foreach ([
            'policy_contract_enforcement.effective_options.agent_behavior_contract.contract_id',
            'engineering_contract.agent_behavior_contract.contract_id',
            'programming_orchestrator.agent_behavior_contract.contract_id',
            'atlas-ai.agent-behavior.v1',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/EngineeringHarnessRunnerTest.php: AP-153 harness behavior contract must be tested [{$token}]";
            }
        }

        foreach ([
            'ABC-6',
            'implemented',
            'ProgrammingExecutionRequest',
            'EngineeringHarnessExecutionService',
            'AP-153',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-153 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'ProgrammingExecutionRequest',
            'EngineeringHarnessExecutionService',
            'AP-153',
            'agent_behavior_contract',
            'Harness executa e valida',
        ] as $token) {
            if (! str_contains($programmingDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/domains/programming.md: AP-153 must be documented in Programming Domain [{$token}]";
            }
        }

        foreach ([
            'AP-153',
            'implemented-harness-contract',
            'ProgrammingExecutionRequest',
            'EngineeringHarnessExecutionService',
            'agent_behavior_contract',
            'KernelArchitectureStaticScanner::scanProgrammingHarnessAgentBehaviorContract',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-153-programming-harness-agent-behavior-contract.md: AP-153 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorEvidenceLedger(): array
    {
        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $evaluatorPath = app_path('Services/Ai/AiQualityEvaluator.php');
        $testPath = base_path('tests/Unit/AiQualityEvaluatorTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-154-agent-behavior-evidence-ledger.md');

        $ledger = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        $evaluator = File::exists($evaluatorPath) ? File::get($evaluatorPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'public function recordAgentBehaviorGateEvaluation(',
            'LedgerEventType::GateEvaluated',
            "'gate_id' => 'atlas.agent_behavior'",
            "'schema_version' => 'atlas.agent_behavior.gate_evaluation.v1'",
            "'agent_behavior_findings' => array_values(\$findings)",
            "'emitter_stage' => 'atlas.agent_behavior_quality_gate'",
        ] as $token) {
            if (! str_contains($ledger, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: AP-154 must record agent behavior gate findings in the Evidence Ledger [{$token}]";
            }
        }

        foreach ([
            'private readonly AtlasEvidenceLedger $ledger',
            'recordAgentBehaviorGateEvaluation($trace, $evaluation, $findings)',
            'evidence.agent_behavior_findings',
        ] as $token) {
            if (! str_contains($evaluator, $token)) {
                $violations[] = "app/Services/Ai/AiQualityEvaluator.php: AP-154 evaluator must emit agent behavior ledger signal [{$token}]";
            }
        }

        foreach ([
            'LedgerEventType::GateEvaluated->value',
            'atlas.agent_behavior_quality_gate',
            'atlas.agent_behavior.gate_evaluation.v1',
            'agent_behavior_findings.0.code',
            'atlas-ai.agent-behavior.v1',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/AiQualityEvaluatorTest.php: AP-154 ledger signal must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-7',
            'implemented',
            'AtlasEvidenceLedger::recordAgentBehaviorGateEvaluation',
            'GATE_EVALUATED',
            'AP-154',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-154 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-154',
            'implemented-ledger-signal',
            'AtlasEvidenceLedger::recordAgentBehaviorGateEvaluation',
            'GATE_EVALUATED',
            'atlas.agent_behavior.gate_evaluation.v1',
            'KernelArchitectureStaticScanner::scanAgentBehaviorEvidenceLedger',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-154-agent-behavior-evidence-ledger.md: AP-154 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorReplayReadModel(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-155-agent-behavior-replay-read-model.md');

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'public function agentBehaviorReportForWindow(',
            'agentBehaviorEventFromEvent',
            'agentBehaviorSummary',
            'agentBehaviorReviewSignal',
            'normalizedAgentBehaviorFilters',
            'matchesAgentBehaviorFilters',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-155 must expose agent behavior replay read model [{$token}]";
            }
        }

        foreach ([
            'test_agent_behavior_window_report_projects_gate_findings',
            'recordAgentBehaviorGateEvent',
            'agentBehaviorReportForWindow',
            'finding_code_counts',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-155 replay read model must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-8',
            'implemented',
            'AtlasLedgerReplayService::agentBehaviorReportForWindow',
            'open_reviewable_agent_behavior_quality_proposal',
            'AP-155',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-155 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-155',
            'implemented-read-model',
            'AtlasLedgerReplayService::agentBehaviorReportForWindow',
            'finding_code_counts',
            'KernelArchitectureStaticScanner::scanAgentBehaviorReplayReadModel',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-155-agent-behavior-replay-read-model.md: AP-155 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorMcpReport(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-156-agent-behavior-mcp-report.md');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            "'name' => 'atlas_agent_behavior_report'",
            "'atlas_agent_behavior_report' => \$this->toolResponse(\$id, \$this->agentBehaviorReport(\$arguments))",
            'private function agentBehaviorReport(array $arguments): array',
            'agentBehaviorReportForWindow',
            "'agent_behavior' => \$report",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-156 must expose agent behavior MCP report [{$token}]";
            }
        }

        foreach ([
            'test_agent_behavior_report_summarizes_gate_findings',
            'recordAgentBehaviorForMcp',
            'atlas_agent_behavior_report',
            'agent_behavior.finding_code_counts',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-156 MCP report must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-9',
            'implemented',
            'atlas_agent_behavior_report',
            'AP-156',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-156 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-156',
            'implemented-mcp-report',
            'atlas_agent_behavior_report',
            'AtlasLedgerReplayService::agentBehaviorReportForWindow',
            'KernelArchitectureStaticScanner::scanAgentBehaviorMcpReport',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-156-agent-behavior-mcp-report.md: AP-156 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorSelfImprovementReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-157-agent-behavior-self-improvement-review.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'private function agentBehaviorReplayFindings(',
            'agentBehaviorReportForWindow',
            'atlas.self_improvement.agent_behavior_replay.v1',
            'open_reviewable_agent_behavior_quality_proposal',
            'normalizedAgentBehaviorFilters',
            'self-improvement:agent-behavior-replay',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-157 must let Self-Improvement consume agent behavior replay [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_agent_behavior_replay_patterns',
            'recordAgentBehaviorGateEvent',
            'agent.verification_missing',
            'atlas.self_improvement.agent_behavior_replay.v1',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-157 Self-Improvement review must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-10',
            'implemented',
            'agentBehaviorReplayFindings',
            'AP-157',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-157 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-157',
            'implemented-self-improvement-review',
            'agentBehaviorReportForWindow',
            'atlas.self_improvement.agent_behavior_replay.v1',
            'KernelArchitectureStaticScanner::scanAgentBehaviorSelfImprovementReview',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-157-agent-behavior-self-improvement-review.md: AP-157 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorDirectSurfaces(): array
    {
        $commandPath = app_path('Console/Commands/AtlasAiAgentBehaviorReportCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiAgentBehaviorReportController.php');
        $routesPath = base_path('routes/api.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiAgentBehaviorReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiAgentBehaviorReportApiTest.php');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-158-agent-behavior-direct-surfaces.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            'atlas:ai:agent-behavior-report',
            'agentBehaviorReportForWindow',
            "'agent_behavior' => \$report",
            'finding-code',
            'contract-id',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiAgentBehaviorReportCommand.php: AP-158 must expose CLI report [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiAgentBehaviorReportController',
            'agentBehaviorReportForWindow',
            "'agent_behavior' => \$report",
            'finding_code',
            'contract_id',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiAgentBehaviorReportController.php: AP-158 must expose API report [{$token}]";
            }
        }

        foreach ([
            'AtlasAiAgentBehaviorReportController',
            '/ai/agent-behavior/report',
        ] as $token) {
            if (! str_contains($routes, $token)) {
                $violations[] = "routes/api.php: AP-158 route must be registered [{$token}]";
            }
        }

        foreach ([
            'agent_behavior_report',
            'atlas ai agent-behavior-report --hours=24 --json',
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-158 must be discoverable in operations catalog [{$token}]";
            }
        }

        foreach ([
            'AtlasAiAgentBehaviorReportCommandTest',
            'test_command_summarizes_agent_behavior_report_as_json',
            'atlas:ai:agent-behavior-report',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiAgentBehaviorReportCommandTest.php: AP-158 CLI surface must be covered [{$token}]";
            }
        }

        foreach ([
            'AtlasAiAgentBehaviorReportApiTest',
            '/ai/agent-behavior/report',
            'test_agent_behavior_report_api_returns_filtered_window_summary',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiAgentBehaviorReportApiTest.php: AP-158 API surface must be covered [{$token}]";
            }
        }

        foreach ([
            'ABC-11',
            'implemented',
            'atlas:ai:agent-behavior-report',
            'AP-158',
        ] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-158 must be reflected in behavior contract doc [{$token}]";
            }
        }

        foreach ([
            'AP-158',
            'implemented-direct-surfaces',
            'atlas:ai:agent-behavior-report',
            '/ai/agent-behavior/report',
            'KernelArchitectureStaticScanner::scanAgentBehaviorDirectSurfaces',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-158-agent-behavior-direct-surfaces.md: AP-158 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorDedicatedCuratorFlow(): array
    {
        $orchestratorPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $profileRegistryPath = app_path('Services/Ai/AtlasDomainProfileRegistry.php');
        $configPath = config_path('atlas_ai.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $helpTestPath = base_path('tests/Feature/AtlasCliHelpCommandTest.php');
        $orchestratorTestPath = base_path('tests/Unit/Ai/AtlasSelfImprovementOrchestratorTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $catalogTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $domainProfileTestPath = base_path('tests/Feature/Architecture/DomainProfileComplianceTest.php');
        $domainDocPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $contractDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $kernelDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-159-agent-behavior-dedicated-curator-flow.md');

        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $profileRegistry = File::exists($profileRegistryPath) ? File::get($profileRegistryPath) : '';
        $config = File::exists($configPath) ? File::get($configPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $helpTest = File::exists($helpTestPath) ? File::get($helpTestPath) : '';
        $orchestratorTest = File::exists($orchestratorTestPath) ? File::get($orchestratorTestPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $catalogTest = File::exists($catalogTestPath) ? File::get($catalogTestPath) : '';
        $domainProfileTest = File::exists($domainProfileTestPath) ? File::get($domainProfileTestPath) : '';
        $domainDoc = File::exists($domainDocPath) ? File::get($domainDocPath) : '';
        $contractDoc = File::exists($contractDocPath) ? File::get($contractDocPath) : '';
        $kernelDoc = File::exists($kernelDocPath) ? File::get($kernelDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach (["'self_improvement.agent_behavior_review'", 'SUPPORTED_FLOWS'] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php: AP-159 dedicated agent behavior flow must be supported [{$token}]";
            }
        }

        foreach ([
            "'self_improvement.agent_behavior_review' => [",
            '...$this->agentBehaviorReplayFindings($hours, $filters)',
            'atlas.self_improvement.agent_behavior_replay.v1',
            'agentBehaviorReportForWindow',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-159 runtime must route dedicated flow to agent behavior replay only [{$token}]";
            }
        }

        foreach (["'self_improvement.agent_behavior_review'", "'Agent Behavior Review'", "'agent_behavior_review_runtime'"] as $token) {
            if (! str_contains($profileRegistry, $token)) {
                $violations[] = "app/Services/Ai/AtlasDomainProfileRegistry.php: AP-159 domain profile must declare agent behavior review [{$token}]";
            }
        }

        if (! str_contains($config, "'self_improvement.agent_behavior_review'")) {
            $violations[] = "config/atlas_ai.php: AP-159 orchestrator config must declare agent behavior review ['self_improvement.agent_behavior_review']";
        }

        foreach ([
            'agent_behavior_curator_review',
            'atlas ai self-improve --flow=agent_behavior_review --hours=168 --json',
            'curator_review',
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-159 operation must be discoverable [{$token}]";
            }
            if (! str_contains($catalogTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-159 catalog operation must be tested [{$token}]";
            }
        }

        foreach (['atlas ai self-improve --flow=agent_behavior_review --hours=168 --json'] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-159 MCP catalog parity must be covered [{$token}]";
            }
            if (! str_contains($helpTest, $token)) {
                $violations[] = "tests/Feature/AtlasCliHelpCommandTest.php: AP-159 CLI help discovery must be covered [{$token}]";
            }
        }

        foreach ([
            'test_agent_behavior_review_plan_uses_dedicated_executor_contract',
            'self_improvement.agent_behavior_review',
            'agent_behavior_review_runtime',
        ] as $token) {
            if (! str_contains($orchestratorTest, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasSelfImprovementOrchestratorTest.php: AP-159 orchestrator plan must be covered [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_agent_behavior_replay_patterns',
            "flow: 'self_improvement.agent_behavior_review'",
            'self_improvement.agent_behavior_review',
            'open_reviewable_agent_behavior_quality_proposal',
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-159 runtime flow must be covered [{$token}]";
            }
        }

        foreach (['self_improvement.agent_behavior_review'] as $token) {
            if (! str_contains($domainProfileTest, $token)) {
                $violations[] = "tests/Feature/Architecture/DomainProfileComplianceTest.php: AP-159 domain profile compliance must include flow [{$token}]";
            }
            if (! str_contains($domainDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/domains/self-improvement.md: AP-159 domain docs must include flow [{$token}]";
            }
        }

        foreach (['ABC-12', 'AP-159', 'self_improvement.agent_behavior_review', 'atlas ai self-improve --flow=agent_behavior_review --hours=168 --json'] as $token) {
            if (! str_contains($contractDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md: AP-159 behavior contract doc must include dedicated flow [{$token}]";
            }
        }

        foreach (['AP-159', 'agent_behavior_curator_review', 'self_improvement.agent_behavior_review'] as $token) {
            if (! str_contains($kernelDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-159 kernel architecture doc must include flow [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-159-agent-behavior-dedicated-curator-flow.md: AP-159 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorCuratorFilterSurface(): array
    {
        $commandPath = app_path('Console/Commands/AtlasAiSelfImproveCommand.php');
        $orchestratorPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $apDocPath = base_path('docs/ap/AP-160-agent-behavior-curator-filter-surface.md');
        $ap159DocPath = base_path('docs/ap/AP-159-agent-behavior-dedicated-curator-flow.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $ap159Doc = File::exists($ap159DocPath) ? File::get($ap159DocPath) : '';
        $violations = [];

        foreach ([
            '{--agent-status= : Filter Agent Behavior findings by gate status}',
            '{--agent-slug= : Filter Agent Behavior findings by agent slug}',
            '{--finding-code= : Filter Agent Behavior findings by finding code}',
            '{--contract-id= : Filter Agent Behavior findings by behavior contract id}',
            "'agent-status' => 'status'",
            "'agent-slug' => 'agent_slug'",
            "'finding-code' => 'finding_code'",
            "'contract-id' => 'contract_id'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImproveCommand.php: AP-160 self-improve CLI must expose agent behavior filters [{$token}]";
            }
        }

        foreach ([
            "'agent_slug' => ['agent_slug']",
            "'finding_code' => ['finding_code']",
            "'contract_id' => ['contract_id']",
            "'agent_slug', 'finding_code', 'contract_id'",
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php: AP-160 orchestrator must preserve agent behavior filters [{$token}]";
            }
        }

        foreach ([
            'normalizedAgentBehaviorFilters',
            "'agent_slug'",
            "'finding_code'",
            "'contract_id'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-160 runtime must normalize agent behavior filters [{$token}]";
            }
        }

        foreach ([
            'test_command_filters_agent_behavior_review_by_behavior_dimensions',
            "'--agent-slug' => 'programming_agent'",
            "'--finding-code' => 'agent.verification_missing'",
            "'--contract-id' => 'atlas-ai.agent-behavior.v1'",
            "'--agent-status' => 'needs_review'",
            "'agent_slug' => 'programming_agent'",
            "'finding_code' => 'agent.verification_missing'",
            "'contract_id' => 'atlas-ai.agent-behavior.v1'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-160 CLI filter contract must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-160',
            'agent behavior filters',
            '--agent-slug',
            '--finding-code',
            '--contract-id',
            '--agent-status',
            'ap160_agent_behavior_curator_filter_surface',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-160-agent-behavior-curator-filter-surface.md: AP-160 contract doc must exist [{$token}]";
            }
        }

        foreach (['AP-160', '--agent-slug', '--finding-code', '--contract-id', '--agent-status'] as $token) {
            if (! str_contains($ap159Doc, $token)) {
                $violations[] = "docs/ap/AP-159-agent-behavior-dedicated-curator-flow.md: AP-160 must update AP-159 flow usage docs [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorRecurringSchedule(): array
    {
        $configPath = config_path('atlas_ai.php');
        $schedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $unitTestPath = base_path('tests/Unit/Ai/AtlasSelfImprovementScheduleServiceTest.php');
        $featureTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSelfImprovementScheduleApiTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $indexDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $apDocPath = base_path('docs/ap/AP-161-agent-behavior-recurring-schedule.md');

        $config = File::exists($configPath) ? File::get($configPath) : '';
        $schedule = File::exists($schedulePath) ? File::get($schedulePath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';
        $indexDocs = File::exists($indexDocsPath) ? File::get($indexDocsPath) : '';
        $domainDocs = File::exists($domainDocsPath) ? File::get($domainDocsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $tests = implode("\n", [$unitTest, $featureTest, $apiTest, $mcpTest, $observabilityTest]);
        $docs = implode("\n", [$kernelDocs, $indexDocs, $domainDocs, $apDoc]);
        $violations = [];

        foreach ([
            'nightly_review,weekly_architecture_audit,repair_loop_review,kernel_pipeline_review,agent_behavior_review',
            "'self_improvement.agent_behavior_review'",
        ] as $token) {
            if (! str_contains($config, $token)) {
                $violations[] = "config/atlas_ai.php: AP-161 default config must keep agent behavior in the recurring Self-Improvement schedule [{$token}]";
            }
        }

        foreach ([
            "'agent_behavior_review'",
            'Restore the default nightly_review, weekly_architecture_audit, repair_loop_review, kernel_pipeline_review, and agent_behavior_review schedule.',
            'public function defaultFlows(): array',
        ] as $token) {
            if (! str_contains($schedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: AP-161 schedule service must include agent_behavior_review by default [{$token}]";
            }
        }

        foreach ([
            'atlas:ai:self-improve --flow=agent_behavior_review --hours=24 --limit=5 --json',
            'registered_command_count',
            "['daily' => 4, 'weekly' => 1]",
            'agent_behavior_review',
            'commands.4.command',
        ] as $token) {
            if (! str_contains($tests, $token)) {
                $violations[] = "tests: AP-161 recurring agent behavior schedule must be locked by schedule/API/MCP/observability tests [{$token}]";
            }
        }

        foreach ([
            'AP-161',
            'agent_behavior_review',
            '13 flow profiles',
            'default recorrente',
            'ap161_agent_behavior_recurring_schedule',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs: AP-161 recurring agent behavior schedule must be documented in AP/kernel/index/domain docs [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAgentBehaviorProposalGovernance(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $behaviorDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md');
        $apDocPath = base_path('docs/ap/AP-162-agent-behavior-proposal-governance.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = File::exists($kernelDocsPath) ? File::get($kernelDocsPath) : '';
        $behaviorDocs = File::exists($behaviorDocsPath) ? File::get($behaviorDocsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $docs = implode("\n", [$kernelDocs, $behaviorDocs, $apDoc]);
        $violations = [];

        foreach ([
            "'available_actions' => \$availableActions",
            "'auto_apply_behavior_change' => false",
            "'requires_operator_review' => true",
            "'requires_architecture_validate' => true",
            "'agent_behavior_replay' => [",
            'atlas.self_improvement.agent_behavior_replay.proposal_payload.v1',
            "'critical_behavior_change_requires_human_review' => true",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-162 agent behavior proposal must preserve review governance [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_emits_agent_behavior_replay_proposal_with_review_governance',
            'atlas.self_improvement.agent_behavior_replay.v1',
            'open_reviewable_agent_behavior_quality_proposal',
            "'review_patch'",
            "'discuss'",
            "'discard'",
            'auto_apply_behavior_change',
            'requires_architecture_validate',
            'emitted_to_inbox',
            'emitted_inbox_item_id',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-162 inbox emission and governance must be tested [{$token}]";
            }
        }

        foreach ([
            'AP-162',
            'Agent Behavior Proposal Governance',
            'auto_apply_behavior_change=false',
            'atlas.self_improvement.agent_behavior_replay.proposal_payload.v1',
            'ap162_agent_behavior_proposal_governance',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs: AP-162 agent behavior proposal governance must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProviderCostRateInboxReplay(): array
    {
        $inboxActionsPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $commandPath = app_path('Console/Commands/AtlasAiInboxActionReportCommand.php');
        $ledgerReplayTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $inboxActionTestPath = base_path('tests/Feature/Ai/InboxLedgerProjectionActionTest.php');
        $selfImprovementTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-146-provider-cost-rate-inbox-replay.md');

        $inboxActions = File::exists($inboxActionsPath) ? File::get($inboxActionsPath) : '';
        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $selfImprovement = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $ledgerReplayTest = File::exists($ledgerReplayTestPath) ? File::get($ledgerReplayTestPath) : '';
        $inboxActionTest = File::exists($inboxActionTestPath) ? File::get($inboxActionTestPath) : '';
        $selfImprovementTest = File::exists($selfImprovementTestPath) ? File::get($selfImprovementTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            "'configure_provider_cost_rates' => \$this->configureProviderCostRates(\$locked, \$input)",
            'private readonly AiProviderCostRateService $providerCostRates',
            'private function configureProviderCostRates(AiInboxItem $item, array $input): array',
            "'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1'",
            '$this->providerCostRates->upsert($rateTemplate)',
            'recordInboxActionLedgerEvent($fresh, $actionId, $serializedResult, $actor, $idempotencyKey)',
        ] as $token) {
            if (! str_contains($inboxActions, $token)) {
                $violations[] = "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-146 provider cost-rate Inbox action must record applied/previewed rates [{$token}]";
            }
        }

        foreach ([
            'provider_cost_rate_action_count',
            'provider_cost_rate_applied_count',
            'provider_cost_rate_provider_counts',
            'provider_cost_rate_model_counts',
            'provider_cost_rate_schema_version',
            'provider_cost_rate_input_microusd',
            'provider_cost_rate_output_microusd',
            'provider_cost_rate_applied',
            'provider_cost_rates_configured',
            'configure_provider_cost_rates_action_without_applied_rate',
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-146 provider cost-rate action must be projected by inbox replay [{$token}]";
            }
        }

        foreach ([
            'configure_provider_cost_rates_action_without_applied_rate',
            'Completar rates de custo dos providers no Inbox',
            'atlas.provider_cost_rates.curator_completion_request.v1',
            "'available_actions' => [",
            "'id' => 'configure_provider_cost_rates'",
            "'gap_type' => \$providerCostRateGapReason",
        ] as $token) {
            if (! str_contains($selfImprovement, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-146 Curator must reopen previewed provider cost-rate actions [{$token}]";
            }
        }

        foreach ([
            'renderProviderCostRateSummary',
            'Provider cost-rate actions',
            'Applied cost-rate actions',
            'provider_cost_rate_provider_counts',
            'provider_cost_rate_model_counts',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiInboxActionReportCommand.php: AP-146 human CLI report must expose provider cost-rate summary [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_window_report_projects_provider_cost_rate_actions',
            'test_inbox_action_window_report_warns_when_provider_cost_rate_action_is_only_previewed',
            'provider_cost_rate_action_count',
            'provider_cost_rates_configured',
            'configure_provider_cost_rates_action_without_applied_rate',
        ] as $token) {
            if (! str_contains($ledgerReplayTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-146 replay projection must be tested [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_configures_provider_cost_rates_with_human_supplied_rates_and_ledger_evidence',
            'test_inbox_action_previews_provider_cost_rate_template_without_resolving_item',
            'atlas.inbox_action.provider_cost_rates.v1',
            'configure_provider_cost_rates',
        ] as $token) {
            if (! str_contains($inboxActionTest, $token)) {
                $violations[] = "tests/Feature/Ai/InboxLedgerProjectionActionTest.php: AP-146 Inbox action execution must be tested [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_provider_cost_rate_action_without_applied_rate',
            'test_self_improvement_does_not_flag_provider_cost_rate_action_when_rate_was_applied',
            'atlas.provider_cost_rates.curator_completion_request.v1',
            'configure_provider_cost_rates_action_without_applied_rate',
        ] as $token) {
            if (! str_contains($selfImprovementTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-146 Self-Improvement replay gap must be tested [{$token}]";
            }
        }

        foreach ([
            'test_command_human_output_includes_provider_cost_rate_summary_when_configured',
            'Provider cost-rate actions',
            'Applied cost-rate actions',
            'codex_cli:gpt-5.2',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php: AP-146 human CLI summary must be tested [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_report_tool_exposes_provider_cost_rate_actions',
            'provider_cost_rate_action_count',
            'configure_provider_cost_rates',
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-146 MCP report must expose provider cost-rate actions [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_exposes_provider_cost_rate_inbox_actions',
            'inbox_actions.provider_cost_rate_action_count',
            'provider_cost_rate_applied_count',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-146 Observability must expose provider cost-rate replay [{$token}]";
            }
        }

        foreach ([
            'AP-146',
            'Provider Cost Rate Inbox Replay Contract',
            'configure_provider_cost_rates',
            'atlas.inbox_action.provider_cost_rates.v1',
            'provider_cost_rates_configured',
            'configure_provider_cost_rates_action_without_applied_rate',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-146 Provider Cost Rate Inbox Replay must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-146-provider-cost-rate-inbox-replay.md: AP-146 contract doc must exist [{$token}]";
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

    /**
     * @return array<int,string>
     */
    private function scanSloObservability(): array
    {
        $probePath = app_path('Services/Ai/Kernel/Slo/KernelSloProbe.php');
        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $eventTypePath = app_path('Services/Ai/Kernel/Evidence/LedgerEventType.php');
        $decidePath = app_path('Services/Ai/AtlasDecideService.php');
        $contextPath = app_path('Services/Ai/AiContextPackBuilder.php');
        $toolGatePath = app_path('Services/Tools/AtlasToolGateService.php');
        $workerPath = app_path('Services/Ai/AiWorker.php');
        $surfaceAdapterPath = app_path('Services/Ai/Surface/Adapters/BaseSurfaceAdapter.php');
        $learningPromotionPath = app_path('Services/Ai/AtlasMemoryLearningPromotionService.php');
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $violations = [];

        if (! File::exists($probePath)) {
            $violations[] = 'app/Services/Ai/Kernel/Slo/KernelSloProbe.php: missing SLO probe service';
        }

        $probe = File::exists($probePath) ? File::get($probePath) : '';
        $ledger = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        $eventType = File::exists($eventTypePath) ? File::get($eventTypePath) : '';
        $decide = File::exists($decidePath) ? File::get($decidePath) : '';
        $context = File::exists($contextPath) ? File::get($contextPath) : '';
        $toolGate = File::exists($toolGatePath) ? File::get($toolGatePath) : '';
        $worker = File::exists($workerPath) ? File::get($workerPath) : '';
        $surfaceAdapter = File::exists($surfaceAdapterPath) ? File::get($surfaceAdapterPath) : '';
        $learningPromotion = File::exists($learningPromotionPath) ? File::get($learningPromotionPath) : '';
        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $observability = File::exists($observabilityPath) ? File::get($observabilityPath) : '';

        $probeChecks = [
            'probe assesses stage through KernelSloTargets' => 'targets->assess($stage, $durationMs, $success)',
            'probe records assessment to Evidence Ledger' => 'ledger->recordSloObservation($assessment, $context)',
            'probe supports callable measurement' => 'public function measure(string $stage, callable $callback',
            'probe records failed callable stages' => 'observe($stage, $this->durationMs($started), false, $context)',
        ];

        foreach ($probeChecks as $label => $token) {
            if (! str_contains($probe, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Slo/KernelSloProbe.php: missing {$label} [{$token}]";
            }
        }

        if (! str_contains($ledger, 'recordSloObservation(') || ! str_contains($ledger, 'LedgerEventType::SloObserved')) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: missing SLO observation recorder';
        }

        if (! str_contains($ledger, "'emitter_stage' => 'atlas.slo'")) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: SLO observations must use atlas.slo emitter stage';
        }

        if (! str_contains($eventType, "case SloObserved = 'SLO_OBSERVED'")) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/LedgerEventType.php: missing SLO_OBSERVED event type';
        }

        if (! str_contains($decide, "slo->measure('decide.issue'")) {
            $violations[] = 'app/Services/Ai/AtlasDecideService.php: DecisionReceipt issuance must be instrumented with KernelSloProbe stage decide.issue';
        }

        if (! str_contains($decide, "slo->measure('provider.prepare'")) {
            $violations[] = 'app/Services/Ai/AtlasDecideService.php: provider request preparation must be instrumented with KernelSloProbe stage provider.prepare';
        }

        if (! str_contains($context, "slo->measure('context.compose'")) {
            $violations[] = 'app/Services/Ai/AiContextPackBuilder.php: context pack composition must be instrumented with KernelSloProbe stage context.compose';
        }

        if (! str_contains($toolGate, "slo->measure('gate.evaluate'")) {
            $violations[] = 'app/Services/Tools/AtlasToolGateService.php: tool gate evaluation must be instrumented with KernelSloProbe stage gate.evaluate';
        }

        if (! str_contains($worker, "slo->measure('runtime.execute'")) {
            $violations[] = 'app/Services/Ai/AiWorker.php: provider runtime execution must be instrumented with KernelSloProbe stage runtime.execute';
        }

        if (! str_contains($worker, "slo->measure('repair.loop'")) {
            $violations[] = 'app/Services/Ai/AiWorker.php: native programming repair loop must be instrumented with KernelSloProbe stage repair.loop';
        }

        if (! str_contains($surfaceAdapter, "measure('output.render'")) {
            $violations[] = 'app/Services/Ai/Surface/Adapters/BaseSurfaceAdapter.php: surface output rendering must be instrumented with KernelSloProbe stage output.render';
        }

        if (! str_contains($learningPromotion, "slo->measure('learning.project'")) {
            $violations[] = 'app/Services/Ai/AtlasMemoryLearningPromotionService.php: memory learning projection must be instrumented with KernelSloProbe stage learning.project';
        }

        if (! str_contains($replay, 'repairReportForWindow(') || ! str_contains($replay, 'repairReportForEnvelope(')) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: repair evidence must have envelope and window projections for observability/curator';
        }

        if (! str_contains($observability, '$kernelSlo = $ledgerReplay->sloReportForWindow($since)') || ! str_contains($observability, "'kernel_slo' => \$kernelSlo")) {
            $violations[] = 'app/Http/Controllers/AiObservabilityController.php: observability payload must expose kernel_slo read model';
        }

        if (! str_contains($observability, '$kernelRepair = $ledgerReplay->repairReportForWindow($since)') || ! str_contains($observability, "'kernel_repair' => \$kernelRepair")) {
            $violations[] = 'app/Http/Controllers/AiObservabilityController.php: observability payload must expose kernel_slo and kernel_repair read models';
        }

        if (! str_contains($observability, 'AtlasSelfImprovementScheduleService') || ! str_contains($observability, "'self_improvement_schedule' => \$scheduledSelfImprovement")) {
            $violations[] = 'app/Http/Controllers/AiObservabilityController.php: observability payload must expose the Self-Improvement recurring schedule plan';
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanKernelPipelineContract(): array
    {
        $pipelinePath = app_path('Services/Ai/Kernel/Pipeline');
        $requiredFiles = [
            'AtlasKernelPipeline.php',
            'KernelPipelineContract.php',
            'KernelPipelineStage.php',
            'PipelineInput.php',
            'PipelineStageDefinition.php',
            'PipelineStageResult.php',
            'PipelineExecutionResult.php',
            'ScaffoldAtlasKernelPipeline.php',
            'KernelPipelineAuditService.php',
            'KernelPipelineDevPlanBuilder.php',
            'KernelPipelinePlanGuard.php',
            'KernelPipelinePlanViolation.php',
            'KernelPipelineRuntimeGuard.php',
        ];
        $violations = [];

        if (! File::isDirectory($pipelinePath)) {
            return ["missing kernel pipeline directory [{$pipelinePath}]"];
        }

        foreach ($requiredFiles as $file) {
            if (! File::exists($pipelinePath.DIRECTORY_SEPARATOR.$file)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/{$file}: missing pipeline contract file";
            }
        }

        $forbidden = $this->scanPhpFilesForForbiddenTokens($pipelinePath, [
            'App\\Services\\Ai\\Kernel\\Provider\\ProviderDriver',
            'App\\Services\\Ai\\Provider\\Drivers\\',
            'App\\Services\\Ai\\AiGatewayService',
            'App\\Services\\Ai\\AiWorker',
            'ClaudeCliProvider',
            'CodexCliProvider',
            'GeminiCliProvider',
            'ProviderDriverRegistry',
            'provider->execute(',
            'prepareRequest(',
        ]);

        foreach ($forbidden as $violation) {
            $violations[] = $violation;
        }

        $directLedgerWriters = [
            ...$this->scanPhpFilesForForbiddenTokens(app_path('Console/Commands'), [
                'recordKernelPipelineAccepted(',
                'recordKernelPipelineRejected(',
            ]),
            ...$this->scanPhpFilesForForbiddenTokens(app_path('Http/Controllers'), [
                'recordKernelPipelineAccepted(',
                'recordKernelPipelineRejected(',
            ]),
        ];

        foreach ($directLedgerWriters as $violation) {
            $violations[] = 'Kernel Pipeline accepted/rejected events must be emitted through KernelPipelineAuditService: '.$violation;
        }

        $stagePath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineStage.php';
        $pipelinePathname = $pipelinePath.DIRECTORY_SEPARATOR.'ScaffoldAtlasKernelPipeline.php';
        $auditPath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineAuditService.php';
        $devPlanBuilderPath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineDevPlanBuilder.php';
        $contractPath = $pipelinePath.DIRECTORY_SEPARATOR.'AtlasKernelPipeline.php';
        $guardPath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelinePlanGuard.php';
        $runtimeGuardPath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineRuntimeGuard.php';
        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $atlasCliDevCommandPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $aiChatCommandPath = app_path('Console/Commands/AiChatCommand.php');
        $pipelineCommandPath = app_path('Console/Commands/AtlasAiPipelineCommand.php');
        $pipelineControllerPath = app_path('Http/Controllers/AtlasAiPipelineController.php');
        $ledgerCommandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $ledgerControllerPath = app_path('Http/Controllers/AtlasAiLedgerController.php');
        $ledgerReportPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php');
        $ledgerReportPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php');
        $pipelineReportCommandPath = app_path('Console/Commands/AtlasAiKernelPipelineReportCommand.php');
        $pipelineReportControllerPath = app_path('Http/Controllers/AtlasAiKernelPipelineReportController.php');
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $routesPath = base_path('routes/api.php');
        $stageContents = File::exists($stagePath) ? File::get($stagePath) : '';
        $pipelineContents = File::exists($pipelinePathname) ? File::get($pipelinePathname) : '';
        $auditContents = File::exists($auditPath) ? File::get($auditPath) : '';
        $devPlanBuilderContents = File::exists($devPlanBuilderPath) ? File::get($devPlanBuilderPath) : '';
        $contractContents = File::exists($contractPath) ? File::get($contractPath) : '';
        $guardContents = File::exists($guardPath) ? File::get($guardPath) : '';
        $runtimeGuardContents = File::exists($runtimeGuardPath) ? File::get($runtimeGuardPath) : '';
        $ledgerContents = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        $replayContents = File::exists($replayPath) ? File::get($replayPath) : '';
        $atlasCliDevCommandContents = File::exists($atlasCliDevCommandPath) ? File::get($atlasCliDevCommandPath) : '';
        $aiChatCommandContents = File::exists($aiChatCommandPath) ? File::get($aiChatCommandPath) : '';
        $pipelineCommandContents = File::exists($pipelineCommandPath) ? File::get($pipelineCommandPath) : '';
        $pipelineControllerContents = File::exists($pipelineControllerPath) ? File::get($pipelineControllerPath) : '';
        $ledgerCommandContents = File::exists($ledgerCommandPath) ? File::get($ledgerCommandPath) : '';
        $ledgerControllerContents = File::exists($ledgerControllerPath) ? File::get($ledgerControllerPath) : '';
        $ledgerReportContents = File::exists($ledgerReportPath) ? File::get($ledgerReportPath) : '';
        $pipelineReportCommandContents = File::exists($pipelineReportCommandPath) ? File::get($pipelineReportCommandPath) : '';
        $pipelineReportControllerContents = File::exists($pipelineReportControllerPath) ? File::get($pipelineReportControllerPath) : '';
        $observabilityContents = File::exists($observabilityPath) ? File::get($observabilityPath) : '';
        $selfImprovementContents = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        $routesContents = File::exists($routesPath) ? File::get($routesPath) : '';

        foreach (['Input', 'OperationEnvelope', 'Intent', 'Decide', 'DecisionReceipt', 'Domain', 'Context', 'Policy', 'Runtime', 'Gate', 'Repair', 'Evidence', 'Learning', 'Output'] as $case) {
            if (! str_contains($stageContents, "case {$case}")) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelineStage.php: missing canonical stage case [{$case}]";
            }
        }

        foreach (['stages(): array', 'plan(PipelineInput $input): array', 'execute(PipelineInput $input): PipelineExecutionResult', 'complianceReport(): array'] as $contract) {
            if (! str_contains($contractContents, $contract)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/AtlasKernelPipeline.php: missing contract method [{$contract}]";
            }
        }

        foreach ([
            'public static function programmingSurfaces(): array',
            'public static function programmingFlows(): array',
            'public static function programmingInputModes(): array',
            'public static function programmingCommands(): array',
            'public static function surfaceContractSources(): array',
            'public static function requiredSurfaceContract(string $source): array',
            "'atlas_cli_forge'",
        ] as $token) {
            if (! str_contains(File::exists($pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineContract.php') ? File::get($pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineContract.php') : '', $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelineContract.php: programming pipeline allowlists must live in the kernel contract [{$token}]";
            }
        }

        foreach ([
            "'provider_execution_allowed' => false",
            "'runtime_execution_allowed' => false",
            'providerExecutionAttempted: false',
            "'slot_flow_valid'",
            "'schema_version' => KernelPipelineContract::SCHEMA_VERSION",
            "'execution_guards'",
            'KernelPipelineContract::executionGuards($input->dryRun)',
            'planHash:',
        ] as $token) {
            if (! str_contains($pipelineContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/ScaffoldAtlasKernelPipeline.php: scaffold pipeline must keep real execution disabled [{$token}]";
            }
        }

        foreach ([
            'KernelPipelinePlanViolation',
            'KernelPipelineContract::canonicalFlowHash()',
            'KernelPipelineStage::orderedValues()',
            'KernelPipelineContract::programmingSurfaces()',
            'KernelPipelineContract::programmingFlows()',
            'KernelPipelineContract::programmingInputModes()',
            'KernelPipelineContract::programmingCommands()',
            'KernelPipelineContract::surfaceContractSources()',
            'validateSurfaceContract(',
            'validatePlanAndContract(',
            'assertValidPlanAndContract(',
            'kernel_pipeline_contract.required must be true.',
            "'provider_execution_allowed'",
            "'runtime_execution_allowed'",
        ] as $token) {
            if (! str_contains($guardContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelinePlanGuard.php: surface kernel pipeline plans must fail closed before enqueue [{$token}]";
            }
        }

        foreach ([
            'class KernelPipelineRuntimeGuard',
            'public function violationForJob(AiJob $job): ?array',
            'pipelinePlanForJob(',
            'surfaceContractForJob(',
            'auditablePlanForJob(',
            'auditContextForJob(',
            'requiresKernelPipelineContract(',
            '$this->guard->validatePlanAndContract($plan, $contract)',
            "'error_code' => 'kernel_pipeline_contract_violation'",
            "'source' => 'KernelPipelineRuntimeGuard'",
            "'emitter_stage' => 'atlas.ai_worker.kernel_pipeline_runtime_guard'",
        ] as $token) {
            if (! str_contains($runtimeGuardContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelineRuntimeGuard.php: worker runtime must fail closed for invalid dev/forge kernel pipeline contracts [{$token}]";
            }
        }

        foreach ([
            'public function recordKernelPipelineAccepted(array $plan',
            'public function recordKernelPipelineRejected(array $plan',
            'LedgerEventType::KernelPipelineAccepted',
            'LedgerEventType::KernelPipelineRejected',
            'recordKernelPipelineContract(',
            "'violations' => array_values(",
            "'surface_contract' => [",
        ] as $token) {
            if (! str_contains($ledgerContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: kernel pipeline accept/reject must be canonical ledger events [{$token}]";
            }
        }

        foreach ([
            'public function kernelPipelineReportForEnvelope(string $envelopeId): array',
            'public function kernelPipelineReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array',
            'kernelPipelineEventFromEvent(',
            'normalizedKernelPipelineFilters(',
            'matchesKernelPipelineFilters(',
            'LedgerEventType::KernelPipelineAccepted',
            'LedgerEventType::KernelPipelineRejected',
            'has_rejections',
            'surface_contract_source_counts',
            'emitter_stage_counts',
            'surface_contract_source',
        ] as $token) {
            if (! str_contains($replayContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: kernel pipeline events must be projectable from ledger replay [{$token}]";
            }
        }

        foreach ([
            'class KernelPipelineAuditService',
            'public function recordScaffoldExecution(',
            'public function recordAcceptedPlan(array $plan',
            'public function recordRejectedPlan(array $plan',
            'PipelineExecutionResult $result',
            'return $this->recordAcceptedPlan($result->auditPlan',
            '$this->ledger->recordKernelPipelineAccepted(',
            '$this->ledger->recordKernelPipelineRejected(',
            "'emitter_stage' => \$emitterStage",
            'public function eventPayload(?AtlasLedgerEvent $event): ?array',
            'contextForPlan(',
        ] as $token) {
            if (! str_contains($auditContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelineAuditService.php: scaffold execution auditing must live in kernel service [{$token}]";
            }
        }

        foreach ([
            'class KernelPipelineDevPlanBuilder',
            'public function attachProgrammingPlan(',
            'ScaffoldAtlasKernelPipeline $pipeline',
            'KernelPipelinePlanGuard $guard',
            'PipelineInput::fromArray(',
            "'domain' => 'programming'",
            "KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder')",
            'public function compactPlan(array $plan',
            '$this->guard->assertValidPlanAndContract($devPlan',
        ] as $token) {
            if (! str_contains($devPlanBuilderContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelineDevPlanBuilder.php: dev/forge surfaces must share compact Kernel Pipeline plan builder [{$token}]";
            }
        }

        foreach ([
            'KernelPipelineAuditService',
            'KernelPipelineDevPlanBuilder',
            'recordRejectedPlan(',
            'recordAcceptedPlan(',
            'assertValidPlanAndContract($pipelinePlan',
            "'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard'",
        ] as $token) {
            if (! str_contains($aiChatCommandContents, $token)) {
                $violations[] = "app/Console/Commands/AiChatCommand.php: dev chat kernel pipeline guard must audit through KernelPipelineAuditService [{$token}]";
            }
        }

        foreach ([
            'KernelPipelineAuditService',
            '$audit->recordScaffoldExecution($result)',
            "'ledger_event' => \$audit->eventPayload(\$ledgerEvent)",
        ] as $token) {
            if (! str_contains($pipelineCommandContents, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiPipelineCommand.php: scaffold execution must write a Kernel Pipeline accepted event [{$token}]";
            }
        }

        foreach ([
            'KernelPipelineAuditService',
            '$audit->recordScaffoldExecution($result)',
            "'ledger_event' => \$audit->eventPayload(\$ledgerEvent)",
        ] as $token) {
            if (! str_contains($pipelineControllerContents, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiPipelineController.php: scaffold execution must write a Kernel Pipeline accepted event [{$token}]";
            }
        }

        foreach ([
            'KernelPipelineRuntimeGuard',
            'KernelPipelineAuditService',
            '$this->kernelPipelines->violationForJob($job)',
            '$this->kernelPipelines->auditablePlanForJob($job, $kernelPipelineViolation)',
            '$this->kernelPipelines->auditContextForJob($job)',
            'recordAcceptedKernelPipelineRuntimeContract(',
            'kernel_pipeline_contract_blocked',
            "'kernel_pipeline_contract_enforcement' => \$kernelPipelineViolation",
        ] as $token) {
            if (! str_contains(File::exists(app_path('Services/Ai/AiWorker.php')) ? File::get(app_path('Services/Ai/AiWorker.php')) : '', $token)) {
                $violations[] = "app/Services/Ai/AiWorker.php: worker must enforce Kernel Pipeline contract before provider runtime [{$token}]";
            }
        }

        foreach ([
            'App\\Services\\Ai\\Kernel\\Pipeline\\ScaffoldAtlasKernelPipeline',
            'App\\Services\\Ai\\Kernel\\Pipeline\\PipelineInput',
        ] as $token) {
            foreach ([
                'app/Console/Commands/AtlasCliDevCommand.php' => $atlasCliDevCommandContents,
                'app/Console/Commands/AiChatCommand.php' => $aiChatCommandContents,
            ] as $path => $contents) {
                if (str_contains($contents, $token)) {
                    $violations[] = "{$path}: dev/chat surfaces must build compact kernel pipeline plans through KernelPipelineDevPlanBuilder [{$token}]";
                }
            }
        }

        foreach ([
            '{--kernel : Include Kernel Pipeline contract summary for the envelope}',
            'KernelLedgerEnvelopeReportService $reports',
            'includeKernel: (bool) $this->option(\'kernel\')',
        ] as $token) {
            if (! str_contains($ledgerCommandContents, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLedgerCommand.php: ledger CLI must expose kernel pipeline replay summary [{$token}]";
            }
        }

        foreach ([
            "'kernel' => ['nullable', 'boolean']",
            'KernelLedgerEnvelopeReportService $reports',
            'includeKernel: (bool) ($filters[\'kernel\'] ?? false)',
        ] as $token) {
            if (! str_contains($ledgerControllerContents, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiLedgerController.php: ledger API must expose kernel pipeline replay summary [{$token}]";
            }
        }

        foreach ([
            'kernelPipelineReportForEnvelope($envelopeId)',
            "\$payload['kernel_pipeline']",
        ] as $token) {
            if (! str_contains($ledgerReportContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php: shared ledger report must expose kernel pipeline replay summary [{$token}]";
            }
        }

        foreach ([
            'atlas:ai:kernel-pipeline-report',
            'kernelPipelineReportForWindow(',
            '{--status= : Filter by pipeline contract status}',
            '{--surface= : Filter by surface id}',
            '{--input-mode= : Filter by input mode}',
            '{--contract-source= : Filter by surface contract source}',
            'emitter_stage_counts',
            "'kernel_pipeline' => \$report",
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($pipelineReportCommandContents, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiKernelPipelineReportCommand.php: dedicated Kernel Pipeline report CLI must expose window projection [{$token}]";
            }
        }

        foreach ([
            'AtlasAiKernelPipelineReportController',
            'kernelPipelineReportForWindow(',
            "'status' => ['nullable', 'string', 'max:80']",
            "'surface_id' => ['nullable', 'string', 'max:120']",
            "'input_mode' => ['nullable', 'string', 'max:120']",
            "'contract_source' => ['nullable', 'string', 'max:120']",
            "'kernel_pipeline' => \$report",
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($pipelineReportControllerContents, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiKernelPipelineReportController.php: dedicated Kernel Pipeline report API must expose window projection [{$token}]";
            }
        }

        if (! str_contains($routesContents, 'AtlasAiKernelPipelineReportController') || ! str_contains($routesContents, "Route::get('/ai/kernel-pipeline/report', AtlasAiKernelPipelineReportController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/kernel-pipeline/report must be registered inside the atlas.token API group';
        }

        if (! str_contains($observabilityContents, '$kernelPipeline = $ledgerReplay->kernelPipelineReportForWindow($since)') || ! str_contains($observabilityContents, "'kernel_pipeline' => \$kernelPipeline")) {
            $violations[] = 'app/Http/Controllers/AiObservabilityController.php: observability payload must expose kernel_pipeline read model';
        }

        foreach ([
            'kernelPipelineFindings(',
            'kernelPipelineReportForWindow(',
            "'self-improvement:kernel-pipeline:'",
            'KernelPipelineAccepted',
            'KernelPipelineRejected',
            'has_rejections',
            'emitter_stage_counts',
        ] as $token) {
            if (! str_contains($selfImprovementContents, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must consume Kernel Pipeline replay evidence [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanApAgentWorkflowContracts(): array
    {
        $violations = [];
        $contracts = [
            'AP-200' => [
                'doc' => 'docs/ap/AP-200-ap-agent-handoff-packet.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentHandoffPacket.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentHandoffPacketTest.php',
            ],
            'AP-201' => [
                'doc' => 'docs/ap/AP-201-ap-governance-repair-proposal-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApGovernanceRepairProposalContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApGovernanceRepairProposalContractTest.php',
            ],
            'AP-202' => [
                'doc' => 'docs/ap/AP-202-ap-agent-session-gate.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentSessionGate.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentSessionGateTest.php',
            ],
            'AP-203' => [
                'doc' => 'docs/ap/AP-203-ap-agent-completion-report.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentCompletionReport.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentCompletionReportTest.php',
            ],
            'AP-204' => [
                'doc' => 'docs/ap/AP-204-ap-agent-workflow-registry.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistryTest.php',
            ],
            'AP-205' => [
                'doc' => 'docs/ap/AP-205-ap-validation-evidence-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApValidationEvidenceContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApValidationEvidenceContractTest.php',
            ],
            'AP-206' => [
                'doc' => 'docs/ap/AP-206-ap-agent-workflow-transition-policy.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowTransitionPolicy.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowTransitionPolicyTest.php',
            ],
            'AP-207' => [
                'doc' => 'docs/ap/AP-207-ap-agent-workflow-trace-audit.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowTraceAudit.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowTraceAuditTest.php',
            ],
            'AP-208' => [
                'doc' => 'docs/ap/AP-208-ap-agent-workflow-execution-receipt.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowExecutionReceipt.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowExecutionReceiptTest.php',
            ],
            'AP-209' => [
                'doc' => 'docs/ap/AP-209-ap-agent-workflow-human-review-packet.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanReviewPacket.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanReviewPacketTest.php',
            ],
            'AP-210' => [
                'doc' => 'docs/ap/AP-210-ap-agent-workflow-human-decision-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanDecisionContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanDecisionContractTest.php',
            ],
            'AP-211' => [
                'doc' => 'docs/ap/AP-211-ap-agent-workflow-integrator-handoff-packet.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorHandoffPacket.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorHandoffPacketTest.php',
            ],
            'AP-212' => [
                'doc' => 'docs/ap/AP-212-ap-agent-workflow-integrator-readiness-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorReadinessContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorReadinessContractTest.php',
            ],
            'AP-213' => [
                'doc' => 'docs/ap/AP-213-ap-agent-workflow-manual-integration-receipt.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowManualIntegrationReceipt.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowManualIntegrationReceiptTest.php',
            ],
            'AP-214' => [
                'doc' => 'docs/ap/AP-214-ap-agent-workflow-final-audit-packet.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalAuditPacket.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalAuditPacketTest.php',
            ],
            'AP-215' => [
                'doc' => 'docs/ap/AP-215-ap-agent-workflow-final-closeout-decision-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalCloseoutDecisionContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalCloseoutDecisionContractTest.php',
            ],
            'AP-216' => [
                'doc' => 'docs/ap/AP-216-ap-agent-workflow-closeout-acceptance-receipt.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowCloseoutAcceptanceReceipt.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowCloseoutAcceptanceReceiptTest.php',
            ],
            'AP-217' => [
                'doc' => 'docs/ap/AP-217-ap-agent-workflow-release-evidence-preflight.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePreflight.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePreflightTest.php',
            ],
            'AP-218' => [
                'doc' => 'docs/ap/AP-218-ap-agent-workflow-release-evidence-handoff-packet.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceHandoffPacket.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceHandoffPacketTest.php',
            ],
            'AP-219' => [
                'doc' => 'docs/ap/AP-219-ap-agent-workflow-release-evidence-candidate-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateContractTest.php',
            ],
            'AP-220' => [
                'doc' => 'docs/ap/AP-220-ap-agent-workflow-release-evidence-candidate-decision-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContractTest.php',
            ],
            'AP-221' => [
                'doc' => 'docs/ap/AP-221-ap-agent-workflow-release-evidence-candidate-decision-receipt.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceiptTest.php',
            ],
            'AP-222' => [
                'doc' => 'docs/ap/AP-222-ap-agent-workflow-release-evidence-execution-readiness-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContractTest.php',
            ],
            'AP-223' => [
                'doc' => 'docs/ap/AP-223-ap-agent-workflow-release-evidence-dry-run-plan-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunPlanContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunPlanContractTest.php',
            ],
            'AP-224' => [
                'doc' => 'docs/ap/AP-224-ap-agent-workflow-release-evidence-dry-run-review-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunReviewContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunReviewContractTest.php',
            ],
            'AP-225' => [
                'doc' => 'docs/ap/AP-225-ap-agent-workflow-release-evidence-dry-run-result-envelope-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContractTest.php',
            ],
            'AP-226' => [
                'doc' => 'docs/ap/AP-226-ap-agent-workflow-release-evidence-dry-run-result-review-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContractTest.php',
            ],
            'AP-227' => [
                'doc' => 'docs/ap/AP-227-ap-agent-workflow-release-evidence-post-dry-run-handoff-packet.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacket.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacketTest.php',
            ],
            'AP-228' => [
                'doc' => 'docs/ap/AP-228-ap-agent-workflow-release-evidence-consumer-readiness-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessContractTest.php',
            ],
            'AP-229' => [
                'doc' => 'docs/ap/AP-229-ap-agent-workflow-release-evidence-consumer-readiness-decision-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionContractTest.php',
            ],
            'AP-230' => [
                'doc' => 'docs/ap/AP-230-ap-agent-workflow-release-evidence-consumer-readiness-decision-receipt.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionReceipt.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionReceiptTest.php',
            ],
            'AP-231' => [
                'doc' => 'docs/ap/AP-231-ap-agent-workflow-release-evidence-execution-authorization-preflight.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationPreflight.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationPreflightTest.php',
            ],
            'AP-232' => [
                'doc' => 'docs/ap/AP-232-ap-agent-workflow-release-evidence-execution-authorization-decision-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionContractTest.php',
            ],
            'AP-233' => [
                'doc' => 'docs/ap/AP-233-ap-agent-workflow-release-evidence-execution-authorization-decision-receipt.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionReceipt.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionReceiptTest.php',
            ],
            'AP-234' => [
                'doc' => 'docs/ap/AP-234-ap-agent-workflow-release-evidence-execution-authorization-handoff-packet.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationHandoffPacket.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationHandoffPacketTest.php',
            ],
            'AP-235' => [
                'doc' => 'docs/ap/AP-235-ap-agent-workflow-release-evidence-execution-implementation-preflight.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationPreflight.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationPreflightTest.php',
            ],
            'AP-236' => [
                'doc' => 'docs/ap/AP-236-ap-agent-workflow-release-evidence-execution-implementation-decision-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionContractTest.php',
            ],
            'AP-237' => [
                'doc' => 'docs/ap/AP-237-ap-agent-workflow-release-evidence-execution-implementation-decision-receipt.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionReceipt.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionReceiptTest.php',
            ],
            'AP-238' => [
                'doc' => 'docs/ap/AP-238-ap-agent-workflow-release-evidence-execution-activation-preflight.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationPreflight.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationPreflightTest.php',
            ],
            'AP-239' => [
                'doc' => 'docs/ap/AP-239-ap-agent-workflow-release-evidence-execution-activation-decision-contract.md',
                'class' => 'app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionContract.php',
                'test' => 'tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionContractTest.php',
            ],
        ];

        foreach ($contracts as $ap => $paths) {
            foreach ($paths as $kind => $relativePath) {
                if (! File::exists(base_path($relativePath))) {
                    $violations[] = "{$relativePath}: missing {$kind} artifact for {$ap} AP agent workflow governance";
                }
            }
        }

        $registryPath = app_path('Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php');
        $registry = File::exists($registryPath) ? File::get($registryPath) : '';
        $registryDocPath = base_path('docs/ap/AP-204-ap-agent-workflow-registry.md');
        $registryDoc = File::exists($registryDocPath) ? File::get($registryDocPath) : '';

        foreach ([
            "'post_completion_review_chain' => \$this->postCompletionReviewChain()",
            'AP-223',
            'AtlasApAgentWorkflowCloseoutAcceptanceReceipt',
            'AtlasApAgentWorkflowReleaseEvidenceHandoffPacket',
            'AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract',
            'AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContract',
            'AtlasApAgentWorkflowReleaseEvidenceDryRunPlanContract',
            'AtlasApAgentWorkflowReleaseEvidenceDryRunReviewContract',
            'AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract',
            'AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContract',
            'AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacket',
            'AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessContract',
            'AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionContract',
            'AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionReceipt',
            'AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationPreflight',
            'AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionContract',
            'AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionReceipt',
            'AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationHandoffPacket',
            'AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationPreflight',
            'AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionContract',
            'AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionReceipt',
            'AtlasApAgentWorkflowReleaseEvidenceExecutionActivationPreflight',
            'AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionContract',
            'atlas engineering knowledge docs-health',
            'php artisan atlas:ai:architecture-readiness --json',
            'atlas engineering knowledge sync --prune',
            'atlas engineering knowledge index-code --prune',
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php: AP agent workflow registry must declare full enterprise workflow and canonical validation commands [{$token}]";
            }
        }

        foreach ([
            'AP-203 continua terminal no trace primario',
            'Cadeia Pos-Completion',
            'AP-216 emite receipt read-only de aceite final',
            'AP-218 entrega pacote read-only ao futuro owner release/evidence',
            'AP-220 registra decisao humana sobre o candidate',
            'AP-222 valida readiness para futura execucao release/ledger',
            'AP-223 planeja dry-run sem executar nem mutar estado',
            'AP-224 revisa plano de dry-run sem executar',
            'AP-225 sela resultado declarado de dry-run sem ledger write',
            'AP-226 revisa resultado de dry-run sem executar release',
            'AP-227 entrega handoff pos-dry-run sem criar job',
            'AP-228 valida readiness do futuro consumidor',
            'AP-229 normaliza decisao humana da readiness',
            'AP-230 emite receipt read-only da decisao da readiness',
            'AP-231 faz preflight de autorizacao de execucao',
            'AP-232 normaliza decisao humana de autorizacao',
            'AP-233 emite receipt read-only da autorizacao',
            'AP-234 entrega handoff de autorizacao para futuro AP de execucao',
            'AP-235 valida preflight de implementacao de execucao',
            'AP-236 normaliza decisao humana da implementacao',
            'AP-237 emite receipt read-only da implementacao',
            'AP-238 valida preflight de ativacao sem ativar',
            'AP-239 normaliza decisao humana de ativacao',
            'architecture-readiness',
            'code intelligence index',
        ] as $token) {
            if (! str_contains($registryDoc, $token)) {
                $violations[] = "docs/ap/AP-204-ap-agent-workflow-registry.md: registry doc must explain primary trace, post-completion chain, and validation commands [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanKernelPipelineHealthReadModel(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $ledgerCommandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $pipelineReportCommandPath = app_path('Console/Commands/AtlasAiKernelPipelineReportCommand.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $selfImprovementTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $ledgerCommand = File::exists($ledgerCommandPath) ? File::get($ledgerCommandPath) : '';
        $pipelineReportCommand = File::exists($pipelineReportCommandPath) ? File::get($pipelineReportCommandPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $selfImprovement = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        $selfImprovementTest = File::exists($selfImprovementTestPath) ? File::get($selfImprovementTestPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            '$health = $this->kernelPipelineHealth($eventCount, $acceptedCount, $rejectedCount)',
            "'health' => \$health",
            'private function kernelPipelineHealth(int $eventCount, int $acceptedCount, int $rejectedCount): array',
            "'rejection_rate' => \$rejectionRate",
            "'review_required' => in_array(\$status, ['warning', 'breach'], true)",
            "'warning_rejection_rate' => \$warningThreshold",
            "'breach_rejection_rate' => \$breachThreshold",
            "'no_kernel_pipeline_events_in_window'",
            "'kernel_pipeline_rejection_rate_above_breach_threshold'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: Kernel Pipeline replay must publish deterministic health [{$token}]";
            }
        }

        foreach ([
            "data_get(\$kernel, 'health.status', 'unknown')",
            "data_get(\$kernel, 'health.rejection_rate', 0)",
        ] as $token) {
            if (! str_contains($ledgerCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLedgerCommand.php: ledger CLI must display Kernel Pipeline health [{$token}]";
            }
        }

        foreach ([
            "data_get(\$pipeline, 'health.status', 'unknown')",
            "data_get(\$pipeline, 'health.rejection_rate', 0)",
            "data_get(\$pipeline, 'health.review_required', false)",
        ] as $token) {
            if (! str_contains($pipelineReportCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiKernelPipelineReportCommand.php: dedicated Kernel Pipeline report must display health [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('kernel_pipeline.health.status', 'breach')",
            "assertJsonPath('kernel_pipeline.health.review_required', true)",
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: observability test must lock Kernel Pipeline health payload [{$token}]";
            }
        }

        if (! str_contains($selfImprovement, "'health' => \$report['health'] ?? []")) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement findings must carry Kernel Pipeline health metadata';
        }

        foreach ([
            "data_get(\$finding, 'metadata.health.status')",
            "data_get(\$finding, 'metadata.health.review_required')",
        ] as $token) {
            if (! str_contains($selfImprovementTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Self-Improvement test must lock Kernel Pipeline health propagation [{$token}]";
            }
        }

        foreach ([
            'health e deterministico',
            '`rejection_rate`, thresholds, reasons e',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: canonical docs must describe Kernel Pipeline health [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRepairLoopContract(): array
    {
        $repairPath = app_path('Services/Ai/Kernel/Repair');
        $requiredFiles = [
            'AtlasRepairOrchestrator.php',
            'RepairAttempt.php',
            'RepairDecision.php',
            'RepairDecisionStatus.php',
            'RepairPolicy.php',
            'RepairReason.php',
            'RepairRequest.php',
            'RepairRequestFactory.php',
            'RepairResult.php',
            'RepairStrategy.php',
            'RepairStrategyResolver.php',
        ];
        $violations = [];

        if (! File::isDirectory($repairPath)) {
            return ["missing repair loop directory [{$repairPath}]"];
        }

        foreach ($requiredFiles as $file) {
            if (! File::exists($repairPath.DIRECTORY_SEPARATOR.$file)) {
                $violations[] = "app/Services/Ai/Kernel/Repair/{$file}: missing repair contract file";
            }
        }

        foreach ($this->scanPhpFilesForForbiddenTokens($repairPath, [
            'App\\Services\\Ai\\Kernel\\Provider\\ProviderDriver',
            'App\\Services\\Ai\\Provider\\Drivers\\',
            'App\\Services\\Ai\\AiGatewayService',
            'App\\Services\\Ai\\AiWorker',
            'ClaudeCliProvider',
            'CodexCliProvider',
            'GeminiCliProvider',
            'ProviderDriverRegistry',
            'provider->execute(',
            'prepareRequest(',
            'Process::run(',
            'Process::start(',
            'exec(',
            'shell_exec(',
        ]) as $violation) {
            $violations[] = $violation;
        }

        $orchestratorPath = $repairPath.DIRECTORY_SEPARATOR.'AtlasRepairOrchestrator.php';
        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';

        foreach ([
            'public function plan(RepairRequest $request): RepairDecision',
            'public function attempt(RepairRequest $request): RepairResult',
            'public function complianceReport(): array',
            'LedgerEventType::RepairInitiated',
            'LedgerEventType::RepairCompleted',
            'executed: false',
            'RepairReason::ExecutionBlockedByDryRun',
            'RepairReason::ExecutionNotImplementedContractFoundationOnly',
            "'execution_enabled' => false",
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Repair/AtlasRepairOrchestrator.php: repair loop must remain scaffold-safe [{$token}]";
            }
        }

        $commandPath = app_path('Console/Commands/AtlasAiRepairCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiRepairController.php');
        $routesPath = base_path('routes/api.php');
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';

        foreach ([
            'AtlasRepairOrchestrator',
            'atlas:ai:repair',
            '{--attempt-repair',
            'dryRun: true',
            'AtlasEvidenceLedger',
            'recordRepairDecision(',
            'recordRepairResult(',
            "'evidence_ledger' => \$this->ledgerEventPayload(\$ledgerEvent)",
            "'completed' => \$this->ledgerEventPayload(\$completedLedgerEvent)",
            "'status' => 'planned_scaffold'",
            "'status' => 'attempted_scaffold'",
            "'compliance' => \$repair->complianceReport()",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiRepairCommand.php: missing safe repair CLI contract [{$token}]";
            }
        }

        foreach ([
            'AtlasRepairOrchestrator',
            'RepairRequest::fromArray',
            'AtlasEvidenceLedger',
            'recordRepairDecision(',
            'recordRepairResult(',
            "'dry_run' => true",
            "'endpoint' => 'POST /ai/repair'",
            "'evidence_ledger' => \$this->ledgerEventPayload(\$ledgerEvent)",
            "'completed' => \$this->ledgerEventPayload(\$completedLedgerEvent)",
            "'status' => 'planned_scaffold'",
            "'status' => 'attempted_scaffold'",
            "'compliance' => \$repair->complianceReport()",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiRepairController.php: missing safe repair API contract [{$token}]";
            }
        }

        if (! str_contains($routes, 'AtlasAiRepairController') || ! str_contains($routes, "Route::post('/ai/repair', AtlasAiRepairController::class);")) {
            $violations[] = 'routes/api.php: POST /ai/repair must be registered inside the atlas.token API group';
        }

        $workerPath = app_path('Services/Ai/AiWorker.php');
        $worker = File::exists($workerPath) ? File::get($workerPath) : '';
        foreach ([
            'AtlasRepairOrchestrator',
            'RepairRequestFactory',
            'nativeProgrammingRepairKernelDecision(',
            'nativeProgrammingRepairEvidenceRefs(',
            '$this->repairOrchestrator->plan($request)',
            "'kernel_repair' => \$kernelRepairDecision?->toArray()",
            "'kernel_decision' => \$kernelRepairDecision?->toArray()",
            'kernel_repair_contract_blocks',
        ] as $token) {
            if (! str_contains($worker, $token)) {
                $violations[] = "app/Services/Ai/AiWorker.php: native programming repair must pass through kernel repair contract [{$token}]";
            }
        }

        $programmingPath = app_path('Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $programming = File::exists($programmingPath) ? File::get($programmingPath) : '';
        foreach ([
            'RepairStrategy',
            "\$plan['repair_execution_contract'] = \$this->repairExecutionContract(\$plan);",
            "'kernel_repair_contract' => [",
            "'orchestrator' => 'AtlasRepairOrchestrator'",
            "'request_factory' => 'RepairRequestFactory'",
            "'decision_required_before_enqueue' => true",
            "'blocks_when_kernel_blocks' => true",
            "'allowed_strategies' => RepairStrategy::values()",
            "'requires_evidence_for_heavy_repair' => true",
        ] as $token) {
            if (! str_contains($programming, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php: programming repair contract must declare kernel repair policy [{$token}]";
            }
        }

        $harnessPath = app_path('Services/Engineering/EngineeringHarnessExecutionService.php');
        $harness = File::exists($harnessPath) ? File::get($harnessPath) : '';
        foreach ([
            'AtlasRepairOrchestrator',
            'RepairRequestFactory',
            'AtlasEvidenceLedger',
            'withKernelRepairDecision(',
            'kernelRepairDecision(',
            'recordRepairDecision($decision',
            '$this->repairOrchestrator->plan($repairRequest)',
            "\$result['kernel_repair_decision'] = \$decision->toArray();",
            "'orchestrator' => 'AtlasRepairOrchestrator'",
            "'decision_required_before_enqueue' => true",
            "'blocks_when_kernel_blocks' => true",
            "'executor' => 'engineering_harness'",
        ] as $token) {
            if (! str_contains($harness, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessExecutionService.php: engineering harness failures must attach kernel repair decisions [{$token}]";
            }
        }

        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $ledger = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        foreach ([
            'public function recordRepairDecision(RepairDecision $decision',
            'public function recordRepairResult(RepairResult $result',
            'LedgerEventType::RepairInitiated',
            'LedgerEventType::RepairCompleted',
            "'emitter_stage' => \$context['emitter_stage'] ?? 'atlas.repair'",
            "'causation_id' => \$context['causation_id'] ?? data_get(\$result->decision->evidencePayload, 'decision_hash')",
            "'repair_executed' => false",
        ] as $token) {
            if (! str_contains($ledger, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: repair decisions must be recordable as canonical evidence [{$token}]";
            }
        }

        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $ledgerCommandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $ledgerControllerPath = app_path('Http/Controllers/AtlasAiLedgerController.php');
        $ledgerReportPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php');
        $repairReportCommandPath = app_path('Console/Commands/AtlasAiRepairReportCommand.php');
        $repairReportControllerPath = app_path('Http/Controllers/AtlasAiRepairReportController.php');
        $selfImprovementCommandPath = app_path('Console/Commands/AtlasAiSelfImproveCommand.php');
        $selfImprovementSchedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $selfImprovementScheduleControllerPath = app_path('Http/Controllers/AtlasAiSelfImprovementScheduleController.php');
        $selfImprovementScheduleHealthControllerPath = app_path('Http/Controllers/AtlasAiSelfImprovementScheduleHealthController.php');
        $bootstrapPath = base_path('bootstrap/app.php');
        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $ledgerCommand = File::exists($ledgerCommandPath) ? File::get($ledgerCommandPath) : '';
        $ledgerController = File::exists($ledgerControllerPath) ? File::get($ledgerControllerPath) : '';
        $ledgerReport = File::exists($ledgerReportPath) ? File::get($ledgerReportPath) : '';
        $repairReportCommand = File::exists($repairReportCommandPath) ? File::get($repairReportCommandPath) : '';
        $repairReportController = File::exists($repairReportControllerPath) ? File::get($repairReportControllerPath) : '';
        $selfImprovementCommand = File::exists($selfImprovementCommandPath) ? File::get($selfImprovementCommandPath) : '';
        $selfImprovementSchedule = File::exists($selfImprovementSchedulePath) ? File::get($selfImprovementSchedulePath) : '';
        $selfImprovementScheduleController = File::exists($selfImprovementScheduleControllerPath) ? File::get($selfImprovementScheduleControllerPath) : '';
        $selfImprovementScheduleHealthController = File::exists($selfImprovementScheduleHealthControllerPath) ? File::get($selfImprovementScheduleHealthControllerPath) : '';
        $bootstrap = File::exists($bootstrapPath) ? File::get($bootstrapPath) : '';
        foreach ([
            'public function repairReportForEnvelope(string $envelopeId): array',
            'public function repairReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array',
            'normalizedRepairFilters(',
            'matchesRepairFilters(',
            'LedgerEventType::RepairInitiated',
            'LedgerEventType::RepairCompleted',
            'requires_human_review',
            'repairEventFromEvent(',
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: repair events must be projectable from ledger replay [{$token}]";
            }
        }
        foreach ([
            '{--repair : Include Repair Loop summary for the envelope}',
            'KernelLedgerEnvelopeReportService $reports',
            'includeRepair: (bool) $this->option(\'repair\')',
        ] as $token) {
            if (! str_contains($ledgerCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLedgerCommand.php: ledger CLI must expose repair replay summary [{$token}]";
            }
        }
        foreach ([
            "'repair' => ['nullable', 'boolean']",
            'KernelLedgerEnvelopeReportService $reports',
            'includeRepair: (bool) ($filters[\'repair\'] ?? false)',
        ] as $token) {
            if (! str_contains($ledgerController, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiLedgerController.php: ledger API must expose repair replay summary [{$token}]";
            }
        }
        foreach ([
            'repairReportForEnvelope($envelopeId)',
            "\$payload['repair']",
        ] as $token) {
            if (! str_contains($ledgerReport, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php: shared ledger report must expose repair replay summary [{$token}]";
            }
        }
        foreach ([
            'atlas:ai:repair-report',
            'repairReportForWindow(',
            '{--status= : Filter by repair decision status}',
            '{--strategy= : Filter by repair strategy}',
            '{--failure-domain= : Filter by failure domain}',
            "'kernel_repair' => \$report",
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($repairReportCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiRepairReportCommand.php: dedicated Repair Loop report CLI must expose window projection [{$token}]";
            }
        }
        foreach ([
            'AtlasAiRepairReportController',
            'repairReportForWindow(',
            "'status' => ['nullable', 'string', 'max:80']",
            "'strategy' => ['nullable', 'string', 'max:120']",
            "'failure_domain' => ['nullable', 'string', 'max:160']",
            "'kernel_repair' => \$report",
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($repairReportController, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiRepairReportController.php: dedicated Repair Loop report API must expose window projection [{$token}]";
            }
        }
        if (! str_contains($routes, 'AtlasAiRepairReportController') || ! str_contains($routes, "Route::get('/ai/repair/report', AtlasAiRepairReportController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/repair/report must be registered inside the atlas.token API group';
        }

        foreach ([
            'AtlasSelfImprovementScheduleService',
            '{--schedule-plan : Print the recurring self-improvement schedule plan}',
            '{--schedule-health : Print the compact recurring self-improvement schedule health}',
            '{--fail-on-schedule-warning : Return a non-zero exit code when the recurring schedule health is not healthy}',
            'renderSchedulePlan(',
            'renderScheduleHealth(',
            'Scheduler registration',
            'Registered commands',
            'Skipped reason',
            'schedulePlanExitCode(',
        ] as $token) {
            if (! str_contains($selfImprovementCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImproveCommand.php: Self-Improvement schedule plan must be inspectable from CLI [{$token}]";
            }
        }
        foreach ([
            'schedulePlan()',
            'scheduleHealth()',
            'scheduledCommands()',
            "'schedulable'",
            'isSchedulable(',
            "'scheduler_registration'",
            'schedulerRegistrationForPlan(',
            "'registered_command_count'",
            "'skipped_reason'",
            "'plan_hash'",
            "'plan_hash_algorithm'",
            'planHash(',
            'canonicalize(',
            "'timezone'",
            "'next_run_at'",
            "'invalid_flows'",
            "'defaulted'",
            "'health'",
            'self_improvement_schedule_disabled',
            'invalid_self_improvement_flows_configured',
            'invalid_self_improvement_schedule_time',
            'invalid_self_improvement_schedule_timezone',
            'isValidTimezone(',
            "'nightly_review'",
            "'repair_loop_review'",
            "'kernel_pipeline_review'",
            'SUPPORTED_FLOWS',
        ] as $token) {
            if (! str_contains($selfImprovementSchedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: recurring Self-Improvement schedule must be centralized and include Repair Loop review by default [{$token}]";
            }
        }
        foreach ([
            'AtlasAiSelfImprovementScheduleController',
            'AtlasSelfImprovementScheduleService',
            'schedulePlan()',
        ] as $token) {
            if (! str_contains($selfImprovementScheduleController, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiSelfImprovementScheduleController.php: Self-Improvement schedule plan must be inspectable from API [{$token}]";
            }
        }
        if (! str_contains($routes, 'AtlasAiSelfImprovementScheduleController') || ! str_contains($routes, "Route::get('/ai/self-improvement/schedule', AtlasAiSelfImprovementScheduleController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/self-improvement/schedule must be registered inside the atlas.token API group';
        }
        foreach ([
            'AtlasAiSelfImprovementScheduleHealthController',
            'AtlasSelfImprovementScheduleService',
            'scheduleHealth()',
        ] as $token) {
            if (! str_contains($selfImprovementScheduleHealthController, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiSelfImprovementScheduleHealthController.php: Self-Improvement schedule health must be inspectable from API [{$token}]";
            }
        }
        if (! str_contains($routes, 'AtlasAiSelfImprovementScheduleHealthController') || ! str_contains($routes, "Route::get('/ai/self-improvement/schedule/health', AtlasAiSelfImprovementScheduleHealthController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/self-improvement/schedule/health must be registered inside the atlas.token API group';
        }
        foreach ([
            'AtlasSelfImprovementScheduleService::class',
            'scheduledCommands()',
            "->dailyAt(\$selfImprovementCommand['time'])",
            "->timezone(\$selfImprovementCommand['timezone'])",
            '->withoutOverlapping()',
        ] as $token) {
            if (! str_contains($bootstrap, $token)) {
                $violations[] = "bootstrap/app.php: recurring Self-Improvement scheduler registration must use the centralized schedule contract [{$token}]";
            }
        }

        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $selfImprovement = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        foreach ([
            'repairLoopFindings(',
            'repairReportForWindow(',
            'kernelPipelineFindings(',
            'kernelPipelineReportForWindow(',
            'normalizedRepairFilters(',
            'normalizedKernelPipelineFilters(',
            "'self-improvement:repair-loop:'",
            "'self-improvement:kernel-pipeline:'",
            'requires_human_review',
            'has_rejections',
            'RepairInitiated',
            'RepairCompleted',
            'KernelPipelineAccepted',
            'KernelPipelineRejected',
            'AtlasAiDomainCatalogService',
            'domainOnboardingFindings(',
            'normalizedDomainOnboardingFilters(',
            "'onboarding_status' => 'onboarding_status'",
            "'self-improvement:domain-onboarding:'",
            "'type' => 'domain_catalog'",
            'executable_incomplete_domains',
            'scaffold_domains',
        ] as $token) {
            if (! str_contains($selfImprovement, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must consume Repair Loop, Kernel Pipeline, and Domain Catalog onboarding evidence [{$token}]";
            }
        }

        return $violations;
    }
}
