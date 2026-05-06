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
     *   ap88_test_command_input_contract:array{valid:bool,violations:array<int,string>}
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

        return [
            'ok' => $surfaceProviderBypass === []
                && $surfaceContextBypass === []
                && $decisionReceiptPropagation === []
                && $decisionReceiptRuntimeGuard === []
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
                && $testCommandInputContract === [],
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
        ];
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
            "\$payload = \$this->architectureValidation->payload();",
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
            "\$payload = \$this->architectureValidation->payload();",
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

        if (! str_contains($config, "nightly_review,weekly_architecture_audit,repair_loop_review,kernel_pipeline_review")) {
            $violations[] = 'config/atlas_ai.php: ATLAS_AI_SELF_IMPROVEMENT_FLOWS default must include weekly_architecture_audit between nightly and repair reviews';
        }

        foreach ([
            "'nightly_review',\n            'weekly_architecture_audit',\n            'repair_loop_review',\n            'kernel_pipeline_review'",
            'Restore the default nightly_review, weekly_architecture_audit, repair_loop_review, and kernel_pipeline_review schedule.',
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
            "while ((int) \$next->dayOfWeek !== \$targetWeekDay || \$next->lessThanOrEqualTo(\$now))",
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
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        foreach ([
            'public const DEFAULT_REVIEW_WINDOW_HOURS = 24',
            'public const MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS = 168',
            'public const MAX_FINDINGS_PER_RUN = 20',
            'int $hours = self::DEFAULT_REVIEW_WINDOW_HOURS',
            'min(self::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS, $hours)',
            'min(self::MAX_FINDINGS_PER_RUN, $limit)',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement autonomous runtime limits must be explicit [{$token}]";
            }
        }

        if (str_contains($runtime, 'min(168, $hours)') || str_contains($runtime, 'min(20, $limit)')) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement runtime must not use magic numeric limits for hours/limit';
        }

        foreach ([
            'test_nightly_review_uses_explicit_autonomous_window_and_limit_contract',
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
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
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
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
            'AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS',
            'AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN',
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

        if (! str_contains($resolverTest, "memory_limit=1024M")) {
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
            'gateway emits a trace-level receipt' => "decisionReceiptForTrace(",
            'trace metadata persists the receipt' => "'decision_receipt' => \$decisionReceipt",
            'scout job accepts the receipt as an explicit parameter' => "array \$decisionReceipt",
            'scout job is called with the same receipt' => "decisionReceipt: \$decisionReceipt",
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
            'worker evaluates receipt before provider lookup' => "violationForJob(\$job, \$providerKey, \$job->model)",
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
            'providerDecision honors explicit metadata block' => "metadataExternalAiAllowed !== false",
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
            'private function modelSelectionContract(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode): array',
            '->forCliDev($provider, $modelSelection, $modelOverride, $fairMode)',
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
            'private function modelSelectionContract(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode): array',
            '->forAiChat($provider, $modelSelection, $modelOverride, $fairMode)',
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
            "public const AVAILABLE_SELECTION_MODES = [",
            'public function forCliDev(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode): array',
            'public function forAiChat(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode): array',
            "'schema_version' => \$schemaVersion",
            "'surface' => \$surface",
            "'authority' => self::AUTHORITY",
            "'selection_mode' => \$manual ? 'manual_override' : 'auto_best_allowed'",
            "'available_selection_modes' => self::AVAILABLE_SELECTION_MODES",
            "'operator_requested_provider' => \$provider ?: 'auto'",
            "'requested_model' => \$modelOverride",
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/ModelSelectionContractFactory.php: kernel must own the shared model selection contract shape [{$token}]";
            }
        }

        foreach ([
            'test_cli_dev_contract_defaults_to_auto_best_allowed_under_decide_authority',
            'test_ai_chat_contract_preserves_manual_provider_model_and_alias',
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
            "kernelPipelineReportForEnvelope(\$envelopeId)",
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
            "repairReportForEnvelope(\$envelopeId)",
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
