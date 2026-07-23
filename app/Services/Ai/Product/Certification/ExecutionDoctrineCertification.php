<?php

namespace App\Services\Ai\Product\Certification;

use App\Services\Ai\Product\AtlasAiProductCertificationService;

/**
 * Atlas AI · Product Certification — Execution Doctrine & Operational Quality section.
 *
 * Checks 21-29: frontend operational understanding, assisted execution quality,
 * the execution-doctrine product delivery system, context-memory quality,
 * runtime-efficiency governor, AEMOR runtime, runtime-UX operational and the
 * autonomous evolution loop.
 *
 * Bodies moved verbatim from AtlasAiProductCertificationService; the only
 * rewrites are shared-helper calls and const references
 * (PATH_* -> AtlasAiProductCertificationService::PATH_*).
 */
final class ExecutionDoctrineCertification
{
    public function __construct(private readonly CertificationSupport $support)
    {
    }

    public function frontendOperationalUnderstandingCheck(): array
    {
        $orchestratorSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PROGRAMMING_ORCHESTRATOR));
        $orchestratorTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PROGRAMMING_ORCHESTRATOR_TEST));
        $frontendDocSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PROGRAMMING_FRONTEND_DOC));
        $desktopTripSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_PRODUCT_TRIP_TEST));
        $desktopPresentationSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_PRESENTATION));
        $mobilePresentationSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_MOBILE_PRESENTATION));
        $desktopContextPanelSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_CONTEXT_PANEL));
        $mobileContextSheetSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_MOBILE_CONTEXT_SHEET));

        $frontendProfileRouted = str_contains($orchestratorSource, 'frontendDesignHarnessContract')
            && str_contains($orchestratorSource, 'programming.frontend')
            && str_contains($orchestratorSource, 'atlas.programming.frontend_design_harness.v1');
        $harnessRequiresRealFrontendEvidence = str_contains($orchestratorSource, 'visual_smoke_multi_viewport')
            && str_contains($orchestratorSource, 'asset_provenance_check')
            && str_contains($orchestratorSource, 'design_5d_review')
            && str_contains($orchestratorSource, 'screenshot_alone_is_insufficient')
            && str_contains($orchestratorSource, 'visual_a11y_perf_state_required_or_reason');
        $testsCoverFrontendContract = str_contains($orchestratorTestSource, 'implemente layout frontend mobile com design system')
            && str_contains($orchestratorTestSource, 'frontend_design_harness_contract.schema_version')
            && str_contains($orchestratorTestSource, 'visual_smoke_multi_viewport')
            && str_contains($orchestratorTestSource, 'asset_provenance_check')
            && str_contains($orchestratorTestSource, 'screenshot_alone_is_insufficient');
        $frontendDocsPresent = str_contains($frontendDocSource, 'programming.frontend')
            && str_contains($frontendDocSource, 'frontend_design_harness')
            && str_contains($frontendDocSource, 'visual/a11y/perf')
            && str_contains($frontendDocSource, 'screenshot');
        $desktopTripProvesUserFlow = str_contains($desktopTripSource, 'composer payload')
            && str_contains($desktopTripSource, 'Hyperflow V2 request')
            && str_contains($desktopTripSource, 'Hyperflow V2 response view-model')
            && str_contains($desktopTripSource, 'Response Presentation Contract');
        $presentationContractShared = str_contains($desktopPresentationSource, 'projectPresentation')
            && str_contains($desktopPresentationSource, 'sections: Record<string, string[]>')
            && str_contains($desktopPresentationSource, 'metadata: { sections')
            && str_contains($mobilePresentationSource, 'projectPresentation')
            && str_contains($mobilePresentationSource, 'sections: Record<string, string[]>')
            && str_contains($mobilePresentationSource, 'metadata: { sections');
        $contextSurfacesConsumeRuntime = str_contains($desktopContextPanelSource, 'useHyperflowRuntime')
            && str_contains($desktopContextPanelSource, 'useRuntimeReadiness')
            && str_contains($mobileContextSheetSource, 'useRuntimeReadiness')
            && str_contains($mobileContextSheetSource, 'assistedExecution');

        $passed = $frontendProfileRouted && $harnessRequiresRealFrontendEvidence
            && $testsCoverFrontendContract && $frontendDocsPresent
            && $desktopTripProvesUserFlow && $presentationContractShared
            && $contextSurfacesConsumeRuntime;

        return $this->support->check('frontend_operational_understanding', $passed, 'critical', [
            'frontend_profile_routed' => $frontendProfileRouted,
            'harness_requires_real_frontend_evidence' => $harnessRequiresRealFrontendEvidence,
            'tests_cover_frontend_contract' => $testsCoverFrontendContract,
            'canonical_frontend_doc_present' => $frontendDocsPresent,
            'desktop_trip_proves_user_flow' => $desktopTripProvesUserFlow,
            'presentation_contract_shared_desktop_mobile' => $presentationContractShared,
            'context_surfaces_consume_runtime_readiness' => $contextSurfacesConsumeRuntime,
            'orchestrator_path' => AtlasAiProductCertificationService::PATH_PROGRAMMING_ORCHESTRATOR,
            'orchestrator_test_path' => AtlasAiProductCertificationService::PATH_PROGRAMMING_ORCHESTRATOR_TEST,
            'frontend_doc_path' => AtlasAiProductCertificationService::PATH_PROGRAMMING_FRONTEND_DOC,
            'desktop_trip_test_path' => AtlasAiProductCertificationService::PATH_DESKTOP_PRODUCT_TRIP_TEST,
        ]);
    }

    public function assistedExecutionQualityCheck(): array
    {
        $serviceSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_ASSISTED_EXECUTION_SERVICE));
        $controllerSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AI_INTERACTION_CONTROLLER));
        $testSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_ASSISTED_EXECUTION_TEST));
        $docSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_ASSISTED_EXECUTION_DOC));

        $serviceBuildsHumanEnvelope = str_contains($serviceSource, 'atlas.ai.assisted_execution_quality.v1')
            && str_contains($serviceSource, 'REQUIRED_PIPELINE_STEPS')
            && str_contains($serviceSource, 'REQUIRED_CONTROL_AREAS')
            && str_contains($serviceSource, 'ready_for_assisted_execution')
            && str_contains($serviceSource, 'needs_context');
        $serviceRoutesDevAndForge = str_contains($serviceSource, "'atlas_dev'")
            && str_contains($serviceSource, "'atlas_forge'")
            && str_contains($serviceSource, "'programming.repair'")
            && str_contains($serviceSource, "'programming.forge'");
        $serviceUsesDevRuntimeGate = str_contains($serviceSource, 'DevRuntimeIntelligenceService')
            && str_contains($serviceSource, 'provider_safe')
            && str_contains($serviceSource, 'dev_context_not_provider_safe');
        $serviceUsesRequiredControlAreas = str_contains($serviceSource, 'AtlasExecutionDoctrineRuntimeService')
            && str_contains($serviceSource, 'AtlasExecutionDoctrineGateService')
            && str_contains($serviceSource, 'AtlasCognitiveMemoryFabricService')
            && str_contains($serviceSource, 'AtlasRuntimeEfficiencyGovernorService')
            && str_contains($serviceSource, 'AtlasAemorRuntimeService')
            && str_contains($serviceSource, 'provider_may_run_without_aedpds_gate')
            && str_contains($serviceSource, 'provider_may_run_without_acmf_plan')
            && str_contains($serviceSource, 'provider_may_run_without_areg_decision')
            && str_contains($serviceSource, 'completion_requires_aemor_outcome');
        $serviceClosesAregAemorFeedback = str_contains($serviceSource, 'recordOutcomeFeedback')
            && str_contains($serviceSource, 'atlas.ai.assisted_execution_outcome_feedback.v1')
            && str_contains($serviceSource, 'assisted_execution_feedback')
            && str_contains($serviceSource, 'requires_aemor_judgment_for_learning_promotion')
            && str_contains($serviceSource, 'persist')
            && str_contains($serviceSource, 'driver_effectiveness');
        $serviceProtectsHumanBugPath = str_contains($serviceSource, 'Login|Auth|Session')
            && str_contains($serviceSource, 'workspace_required')
            && str_contains($serviceSource, 'aedpds_gate_blocked')
            && str_contains($serviceSource, 'failure_capsule_or_success')
            && str_contains($serviceSource, 'run_certification');
        $controllerWired = str_contains($controllerSource, 'AtlasAiAssistedExecutionQualityService')
            && str_contains($controllerSource, 'applyAssistedExecutionQuality')
            && str_contains($controllerSource, 'atlas_ai_assisted_execution_quality')
            && $this->support->callOrderInSource($controllerSource, 'applyAssistedExecutionQuality', '$devRuntime->apply');
        $controllerEnforcesGate = str_contains($controllerSource, 'rejectUnsafeAssistedExecution')
            && str_contains($controllerSource, 'assisted_execution_needs_context')
            && str_contains($controllerSource, 'dev_context_not_provider_safe')
            && str_contains($controllerSource, 'provider_execution_allowed');
        $testsCover = str_contains($testSource, 'test_login_bug_human_request_builds_control_area_envelope_and_blocks_without_review')
            && str_contains($testSource, 'test_reviewed_login_bug_can_pass_assisted_execution_gate')
            && str_contains($testSource, 'test_missing_workspace_blocks_before_provider')
            && str_contains($testSource, 'test_large_obra_request_routes_to_forge_without_dev_preview')
            && str_contains($testSource, 'test_hash_is_deterministic_for_same_input')
            && str_contains($testSource, 'test_outcome_feedback_records_areg_and_prepares_aemor_without_writes_by_default')
            && str_contains($testSource, 'test_outcome_feedback_can_persist_areg_and_aemor_when_explicitly_allowed');
        $docPresent = str_contains($docSource, 'runtime_acronym: AAEQ')
            && str_contains($docSource, 'Pedido humano nunca vira provider call bruto')
            && str_contains($docSource, 'Dev so executa se `provider_safe=true`')
            && str_contains($docSource, 'AEDPDS')
            && str_contains($docSource, 'AUCRI/ACMF')
            && str_contains($docSource, 'AREG')
            && str_contains($docSource, 'AEMOR')
            && str_contains($docSource, 'recordOutcomeFeedback')
            && str_contains($docSource, 'atlas.ai.assisted_execution_outcome_feedback.v1');

        $passed = $serviceBuildsHumanEnvelope && $serviceRoutesDevAndForge
            && $serviceUsesDevRuntimeGate && $serviceUsesRequiredControlAreas && $serviceClosesAregAemorFeedback && $serviceProtectsHumanBugPath
            && $controllerWired && $controllerEnforcesGate && $testsCover && $docPresent;

        return $this->support->check('assisted_execution_quality', $passed, 'critical', [
            'service_builds_human_execution_envelope' => $serviceBuildsHumanEnvelope,
            'service_routes_dev_and_forge' => $serviceRoutesDevAndForge,
            'service_uses_dev_runtime_context_gate' => $serviceUsesDevRuntimeGate,
            'service_uses_required_control_areas' => $serviceUsesRequiredControlAreas,
            'service_closes_areg_aemor_feedback' => $serviceClosesAregAemorFeedback,
            'service_protects_login_bug_human_path' => $serviceProtectsHumanBugPath,
            'ai_interaction_controller_wired_before_dev_runtime' => $controllerWired,
            'ai_interaction_controller_enforces_context_gate' => $controllerEnforcesGate,
            'tests_cover_core_paths' => $testsCover,
            'canonical_doc_present' => $docPresent,
            'service_path' => AtlasAiProductCertificationService::PATH_ASSISTED_EXECUTION_SERVICE,
            'controller_path' => AtlasAiProductCertificationService::PATH_AI_INTERACTION_CONTROLLER,
            'test_path' => AtlasAiProductCertificationService::PATH_ASSISTED_EXECUTION_TEST,
            'doc_path' => AtlasAiProductCertificationService::PATH_ASSISTED_EXECUTION_DOC,
        ]);
    }

    public function executionDoctrineProductDeliverySystemCheck(): array
    {
        $truthSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_TRUTH_COMPILER_SERVICE));
        $deliverySource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_RUNTIME_SERVICE));
        $proofSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_FALSIFICATION_SERVICE));
        $enforcementSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_ENFORCEMENT_SERVICE));
        $outcomeSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_SERVICE));
        $runtimeReceiptSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_SERVICE));
        $productTwinSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_TWIN_SIMULATION_SERVICE));
        $productTwinCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_TWIN_SIMULATE_COMMAND));
        $riskGovernorSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_SERVICE));
        $riskGovernorCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_COMMAND));
        $productControlPlaneSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_CONTROL_PLANE_SERVICE));
        $productControlPlaneCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_CONTROL_PLANE_COMMAND));
        $releaseGateSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_RELEASE_GATE_SERVICE));
        $releaseGateCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_RELEASE_GATE_COMMAND));
        $providerMemorySource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_PROVIDER_MEMORY_SERVICE));
        $providerMemoryCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_PROVIDER_MEMORY_COMMAND));
        $policyOptimizerSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_POLICY_OPTIMIZER_SERVICE));
        $policyOptimizerCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_POLICY_OPTIMIZER_COMMAND));
        $repairPlannerSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_MULTI_STEP_REPAIR_PLANNER_SERVICE));
        $repairPlanCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_REPAIR_PLAN_COMMAND));
        $evidenceReplayLabSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_EVIDENCE_REPLAY_LAB_SERVICE));
        $replayLabCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_REPLAY_LAB_COMMAND));
        $doctrineFitnessSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_SERVICE));
        $doctrineFitnessCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_COMMAND));
        $repairBridgeSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_REPAIR_BRIDGE_SERVICE));
        $mutativeRepairSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_MUTATIVE_REPAIR_EXECUTOR_SERVICE));
        $patchRequestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_PATCH_REQUEST_CONTRACT_SERVICE));
        $patchRequestCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_PATCH_REQUEST_COMMAND));
        $patchGateSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_PATCH_PROPOSAL_GATE_SERVICE));
        $mutativeRepairCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_REPAIR_EXECUTE_COMMAND));
        $outcomeModelSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_MODEL));
        $runtimeReceiptModelSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_MODEL));
        $outcomeMigrationSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_MIGRATION));
        $runtimeReceiptMigrationSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_MIGRATION));
        $controllerSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AI_INTERACTION_CONTROLLER));
        $aedpdsDocSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AEDPDS_DOC));
        $apfprDocSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_APFPR_DOC));
        $testSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AEDPDS_TEST));
        $interactionTestSource = $this->support->source($this->support->repoPath('tests/Feature/Ai/AtlasDevRuntimeInteractionApiTest.php'));

        $truthCompilerPresent = str_contains($truthSource, 'atlas.product_truth_contract.v1')
            && str_contains($truthSource, 'execution_lenses')
            && str_contains($truthSource, 'blocked_if_missing')
            && str_contains($truthSource, 'truth_hash');
        $deliveryRuntimePresent = str_contains($deliverySource, 'atlas.autonomous_product_delivery_runtime.v1')
            && str_contains($deliverySource, 'AtlasProductTruthCompilerService')
            && str_contains($deliverySource, 'AtlasAiAssistedExecutionQualityService')
            && str_contains($deliverySource, 'proof_preview')
            && str_contains($deliverySource, 'repair_bridge')
            && str_contains($deliverySource, 'ready_for_delivery');
        $proofRuntimePresent = str_contains($proofSource, 'atlas.product_proof_challenge.v1')
            && str_contains($proofSource, 'missing_test_evidence')
            && str_contains($proofSource, 'missing_security_evidence')
            && str_contains($proofSource, 'acceptanceBlockers')
            && str_contains($proofSource, 'proof_hash');
        $enforcementPresent = str_contains($enforcementSource, 'atlas.product_delivery.enforcement.v1')
            && str_contains($enforcementSource, 'post_execution')
            && str_contains($enforcementSource, 'apfpr_not_ready_for_high_risk_delivery');
        $outcomeMemoryPresent = str_contains($outcomeSource, 'atlas.product_delivery.outcome_memory.v1')
            && str_contains($outcomeSource, 'AtlasProductDeliveryOutcomeMemory')
            && str_contains($outcomeSource, 'should_promote_to_aemor')
            && str_contains($outcomeModelSource, 'atlas_product_delivery_outcome_memories')
            && str_contains($outcomeMigrationSource, 'outcome_memory_hash');
        $runtimeReceiptsPresent = str_contains($runtimeReceiptSource, 'atlas.product_delivery.runtime_receipt.v1')
            && str_contains($runtimeReceiptSource, 'repair_execution')
            && str_contains($runtimeReceiptModelSource, 'append-only')
            && str_contains($runtimeReceiptMigrationSource, 'atlas_product_delivery_runtime_receipts')
            && str_contains($runtimeReceiptMigrationSource, 'receipt_hash');
        $productTwinPresent = str_contains($productTwinSource, 'atlas.product_twin_simulation.v1')
            && str_contains($productTwinSource, 'predicted_impact')
            && str_contains($productTwinSource, 'risk_forecast')
            && str_contains($productTwinSource, 'simulation_hash')
            && str_contains($productTwinCommandSource, 'atlas:product-twin:simulate')
            && str_contains($deliverySource, 'product_twin_simulation')
            && str_contains($deliverySource, 'product_twin_simulation_required');
        $riskGovernorPresent = str_contains($riskGovernorSource, 'atlas.product_delivery.risk_governor.v1')
            && str_contains($riskGovernorSource, 'autonomy_budget')
            && str_contains($riskGovernorSource, 'runtime_signals')
            && str_contains($riskGovernorSource, 'governor_decision')
            && str_contains($riskGovernorSource, 'risk_governor_hash')
            && str_contains($riskGovernorCommandSource, 'atlas:product-delivery:risk-govern')
            && str_contains($deliverySource, 'risk_governor')
            && str_contains($deliverySource, 'risk_governor_required');
        $productControlPlanePresent = str_contains($productControlPlaneSource, 'atlas.product_delivery.control_plane.v1')
            && str_contains($productControlPlaneSource, 'risk_governor')
            && str_contains($productControlPlaneSource, 'doctrine_fitness')
            && str_contains($productControlPlaneSource, 'control_plane_hash')
            && str_contains($productControlPlaneCommandSource, 'atlas:product-delivery:control-plane');
        $releaseGatePresent = str_contains($releaseGateSource, 'atlas.product_delivery.release_gate.v1')
            && str_contains($releaseGateSource, 'release_candidate_allowed')
            && str_contains($releaseGateSource, 'required_green_signals')
            && str_contains($releaseGateSource, 'release_gate_hash')
            && str_contains($releaseGateCommandSource, 'atlas:product-delivery:release-gate');
        $providerMemoryPresent = str_contains($providerMemorySource, 'atlas.product_delivery.provider_cost_flake_memory.v1')
            && str_contains($providerMemorySource, 'provider_failure_count')
            && str_contains($providerMemorySource, 'cost_pressure')
            && str_contains($providerMemorySource, 'provider_memory_hash')
            && str_contains($providerMemoryCommandSource, 'atlas:product-delivery:provider-memory')
            && str_contains($riskGovernorSource, 'provider_memory_feed')
            && str_contains($productControlPlaneSource, 'provider_memory');
        $policyOptimizerPresent = str_contains($policyOptimizerSource, 'atlas.product_delivery.policy_optimizer.v1')
            && str_contains($policyOptimizerSource, 'requires_aemor_judgment')
            && str_contains($policyOptimizerSource, 'requires_human_review')
            && str_contains($policyOptimizerSource, 'policy_optimizer_hash')
            && str_contains($policyOptimizerCommandSource, 'atlas:product-delivery:policy-optimizer');
        $multiStepRepairPlannerPresent = str_contains($repairPlannerSource, 'atlas.product_delivery.multi_step_repair_plan.v1')
            && str_contains($repairPlannerSource, 'rollback_policy')
            && str_contains($repairPlannerSource, 'stop_conditions')
            && str_contains($repairPlannerSource, 'repair_plan_hash')
            && str_contains($repairPlanCommandSource, 'atlas:product-delivery:repair-plan')
            && str_contains($deliverySource, 'multi_step_repair_plan');
        $evidenceReplayLabPresent = str_contains($evidenceReplayLabSource, 'atlas.product_delivery.evidence_replay_lab.v1')
            && str_contains($evidenceReplayLabSource, 'scenario_replay_failed')
            && str_contains($evidenceReplayLabSource, 'unsafe_write_receipts_detected')
            && str_contains($evidenceReplayLabSource, 'replay_hash')
            && str_contains($replayLabCommandSource, 'atlas:product-delivery:replay-lab');
        $doctrineFitnessPresent = str_contains($doctrineFitnessSource, 'atlas.product_delivery.doctrine_fitness.v1')
            && str_contains($doctrineFitnessSource, 'route_fitness')
            && str_contains($doctrineFitnessSource, 'false_learning_guard')
            && str_contains($doctrineFitnessSource, 'fitness_hash')
            && str_contains($doctrineFitnessCommandSource, 'atlas:product-delivery:doctrine-fitness');
        $aemorBridgePresent = str_contains($outcomeSource, 'atlas.product_delivery.aemor_bridge.v1')
            && str_contains($outcomeSource, 'bridgeToAemor')
            && str_contains($outcomeSource, 'AtlasAemorJudgmentService');
        $repairBridgePresent = str_contains($repairBridgeSource, 'atlas.product_delivery.repair_bridge.v1')
            && str_contains($repairBridgeSource, 'DevRepairLoopService')
            && str_contains($repairBridgeSource, 'atlas.forge.apfpr_repair_packet.v1');
        $mutativeRepairExecutorPresent = str_contains($mutativeRepairSource, 'atlas.product_delivery.mutative_repair_executor.v1')
            && str_contains($mutativeRepairSource, 'rollback_snapshot')
            && str_contains($mutativeRepairSource, 'proof_after_repair')
            && str_contains($mutativeRepairCommandSource, 'atlas:product-delivery:repair-execute');
        $patchRequestContractPresent = str_contains($patchRequestSource, 'atlas.product_delivery.patch_request_contract.v1')
            && str_contains($patchRequestSource, 'atlas.product_delivery.patch_manifest.v1')
            && str_contains($patchRequestSource, 'patch_prompt_projection.v1')
            && str_contains($patchRequestCommandSource, 'atlas:product-delivery:patch-request');
        $patchProposalGatePresent = str_contains($patchGateSource, 'atlas.product_delivery.patch_proposal_gate.v1')
            && str_contains($patchGateSource, 'patch_operator_decision.v1')
            && str_contains($patchGateSource, 'auto_apply_provider_patch')
            && str_contains($mutativeRepairCommandSource, 'AtlasProductDeliveryPatchProposalGateService')
            && str_contains($mutativeRepairCommandSource, '--approval=');
        $controllerWired = str_contains($controllerSource, 'AtlasAutonomousProductDeliveryRuntimeService')
            && str_contains($controllerSource, 'applyProductDeliveryRuntime')
            && str_contains($controllerSource, 'atlas_product_delivery_runtime')
            && $this->support->callOrderInSource($controllerSource, 'applyProductDeliveryRuntime', 'applyAssistedExecutionQuality');
        $docsPresent = str_contains($aedpdsDocSource, 'APTC compila a verdade do produto')
            && str_contains($aedpdsDocSource, 'APDR e o motor que executa a regra')
            && str_contains($aedpdsDocSource, 'APFPR tenta provar que a entrega esta errada')
            && str_contains($apfprDocSource, 'Atlas Product Falsification & Proof Runtime')
            && str_contains($apfprDocSource, 'Proof Challenge Report');
        $testsCover = str_contains($testSource, 'test_ecommerce_request_compiles_product_truth_with_enterprise_lenses')
            && str_contains($testSource, 'test_apdr_plans_delivery_with_truth_assisted_execution_and_proof_preview')
            && str_contains($testSource, 'test_apfpr_blocks_complex_delivery_without_evidence')
            && str_contains($testSource, 'test_apfpr_accepts_delivery_with_sufficient_evidence')
            && str_contains($testSource, 'test_post_execution_enforcement_blocks_high_risk_without_ready_proof_and_outcome_memory')
            && str_contains($testSource, 'test_product_delivery_outcome_memory_persists_idempotently')
            && str_contains($testSource, 'test_product_delivery_outcome_memory_bridges_to_aemor_learning_candidate')
            && str_contains($testSource, 'test_product_twin_simulates_contract_test_and_risk_before_execution')
            && str_contains($testSource, 'test_product_twin_command_outputs_simulation_json')
            && str_contains($testSource, 'test_product_delivery_risk_governor_blocks_high_risk_without_operator_approval')
            && str_contains($testSource, 'test_product_delivery_risk_governor_blocks_unsafe_runtime_receipt_history')
            && str_contains($testSource, 'test_product_delivery_risk_governor_reduces_autonomy_from_doctrine_fitness_pressure')
            && str_contains($testSource, 'test_product_delivery_risk_governor_command_outputs_json')
            && str_contains($testSource, 'test_product_delivery_control_plane_aggregates_delivery_risk_replay_fitness_and_certification')
            && str_contains($testSource, 'test_product_delivery_control_plane_blocks_when_replay_or_receipts_are_unsafe')
            && str_contains($testSource, 'test_product_delivery_control_plane_command_outputs_json')
            && str_contains($testSource, 'test_product_release_gate_allows_candidate_only_when_control_plane_is_green')
            && str_contains($testSource, 'test_product_release_gate_blocks_unsafe_replay_and_receipts')
            && str_contains($testSource, 'test_product_release_gate_command_outputs_json')
            && str_contains($testSource, 'test_provider_cost_flake_memory_feed_aggregates_receipts_and_outcomes')
            && str_contains($testSource, 'test_risk_governor_consumes_provider_memory_feed')
            && str_contains($testSource, 'test_product_delivery_provider_memory_command_outputs_json')
            && str_contains($testSource, 'test_product_policy_optimizer_proposes_guarded_changes_from_replay_fitness_and_provider_memory')
            && str_contains($testSource, 'test_product_delivery_policy_optimizer_command_outputs_json')
            && str_contains($testSource, 'test_multi_step_repair_planner_orders_evidence_patch_and_proof_steps')
            && str_contains($testSource, 'test_product_delivery_repair_plan_command_outputs_multistep_plan')
            && str_contains($testSource, 'test_evidence_replay_lab_replays_canonical_scenarios_without_writes')
            && str_contains($testSource, 'test_product_delivery_replay_lab_command_outputs_replay_json')
            && str_contains($testSource, 'test_doctrine_fitness_loop_scores_routes_evidence_and_repairs_from_outcomes')
            && str_contains($testSource, 'test_product_delivery_doctrine_fitness_command_outputs_json')
            && str_contains($testSource, 'test_product_delivery_runtime_receipts_persist_patch_request_append_only')
            && str_contains($testSource, 'test_repair_execute_command_can_persist_gate_and_execution_receipts')
            && str_contains($testSource, 'atlas.programming.dev_repair_receipt.v1')
            && str_contains($testSource, 'atlas.forge.apfpr_repair_packet.v1')
            && str_contains($testSource, 'test_mutative_repair_executor_applies_explicit_patch_and_reruns_proof')
            && str_contains($testSource, 'test_product_delivery_repair_execute_command_runs_dry_run_from_patch_manifest')
            && str_contains($testSource, 'test_patch_request_contract_projects_provider_safe_patch_schema')
            && str_contains($testSource, 'test_product_delivery_patch_request_command_outputs_contract')
            && str_contains($testSource, 'test_patch_proposal_gate_blocks_provider_apply_without_human_approval')
            && str_contains($testSource, 'test_repair_execute_command_applies_provider_patch_with_operator_approval')
            && str_contains($testSource, 'atlas:product-truth:compile')
            && str_contains($testSource, 'atlas:product-delivery:plan')
            && str_contains($testSource, 'atlas:product-twin:simulate')
            && str_contains($testSource, 'atlas:product-delivery:risk-govern')
            && str_contains($testSource, 'atlas:product-delivery:control-plane')
            && str_contains($testSource, 'atlas:product-delivery:release-gate')
            && str_contains($testSource, 'atlas:product-delivery:provider-memory')
            && str_contains($testSource, 'atlas:product-delivery:policy-optimizer')
            && str_contains($testSource, 'atlas:product-delivery:repair-plan')
            && str_contains($testSource, 'atlas:product-delivery:replay-lab')
            && str_contains($testSource, 'atlas:product-delivery:doctrine-fitness')
            && str_contains($testSource, 'atlas:product-proof:challenge')
            && str_contains($testSource, 'atlas:product-delivery:outcome')
            && str_contains($interactionTestSource, 'test_product_request_without_programming_mode_still_gets_delivery_runtime');

        $passed = $truthCompilerPresent && $deliveryRuntimePresent && $proofRuntimePresent
            && $enforcementPresent && $outcomeMemoryPresent && $runtimeReceiptsPresent && $productTwinPresent && $riskGovernorPresent && $productControlPlanePresent && $releaseGatePresent && $providerMemoryPresent && $policyOptimizerPresent && $multiStepRepairPlannerPresent && $evidenceReplayLabPresent && $doctrineFitnessPresent && $aemorBridgePresent && $repairBridgePresent
            && $mutativeRepairExecutorPresent && $patchRequestContractPresent && $patchProposalGatePresent
            && $controllerWired && $docsPresent && $testsCover;

        return $this->support->check('execution_doctrine_product_delivery_system', $passed, 'critical', [
            'product_truth_compiler_present' => $truthCompilerPresent,
            'delivery_runtime_present' => $deliveryRuntimePresent,
            'falsification_proof_runtime_present' => $proofRuntimePresent,
            'product_delivery_enforcement_present' => $enforcementPresent,
            'product_delivery_outcome_memory_present' => $outcomeMemoryPresent,
            'product_delivery_runtime_receipts_present' => $runtimeReceiptsPresent,
            'product_twin_simulation_present' => $productTwinPresent,
            'product_delivery_risk_governor_present' => $riskGovernorPresent,
            'product_delivery_control_plane_present' => $productControlPlanePresent,
            'product_release_gate_present' => $releaseGatePresent,
            'provider_cost_flake_memory_feed_present' => $providerMemoryPresent,
            'product_policy_optimizer_present' => $policyOptimizerPresent,
            'product_delivery_multi_step_repair_planner_present' => $multiStepRepairPlannerPresent,
            'product_delivery_evidence_replay_lab_present' => $evidenceReplayLabPresent,
            'product_delivery_doctrine_fitness_loop_present' => $doctrineFitnessPresent,
            'product_delivery_aemor_bridge_present' => $aemorBridgePresent,
            'product_delivery_repair_bridge_present' => $repairBridgePresent,
            'product_delivery_mutative_repair_executor_present' => $mutativeRepairExecutorPresent,
            'product_delivery_patch_request_contract_present' => $patchRequestContractPresent,
            'product_delivery_patch_proposal_gate_present' => $patchProposalGatePresent,
            'ai_interactions_wires_delivery_runtime_before_assisted_execution' => $controllerWired,
            'canonical_docs_present' => $docsPresent,
            'tests_cover_core_paths' => $testsCover,
            'truth_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_TRUTH_COMPILER_SERVICE,
            'delivery_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_RUNTIME_SERVICE,
            'proof_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_FALSIFICATION_SERVICE,
            'enforcement_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_ENFORCEMENT_SERVICE,
            'outcome_memory_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_SERVICE,
            'runtime_receipt_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_SERVICE,
            'product_twin_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_TWIN_SIMULATION_SERVICE,
            'product_twin_command_path' => AtlasAiProductCertificationService::PATH_PRODUCT_TWIN_SIMULATE_COMMAND,
            'risk_governor_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_SERVICE,
            'risk_governor_command_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_COMMAND,
            'product_control_plane_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_CONTROL_PLANE_SERVICE,
            'product_control_plane_command_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_CONTROL_PLANE_COMMAND,
            'product_release_gate_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_RELEASE_GATE_SERVICE,
            'product_release_gate_command_path' => AtlasAiProductCertificationService::PATH_PRODUCT_RELEASE_GATE_COMMAND,
            'provider_memory_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_PROVIDER_MEMORY_SERVICE,
            'provider_memory_command_path' => AtlasAiProductCertificationService::PATH_PRODUCT_PROVIDER_MEMORY_COMMAND,
            'product_policy_optimizer_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_POLICY_OPTIMIZER_SERVICE,
            'product_policy_optimizer_command_path' => AtlasAiProductCertificationService::PATH_PRODUCT_POLICY_OPTIMIZER_COMMAND,
            'multi_step_repair_planner_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_MULTI_STEP_REPAIR_PLANNER_SERVICE,
            'repair_plan_command_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_REPAIR_PLAN_COMMAND,
            'evidence_replay_lab_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_EVIDENCE_REPLAY_LAB_SERVICE,
            'replay_lab_command_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_REPLAY_LAB_COMMAND,
            'doctrine_fitness_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_SERVICE,
            'doctrine_fitness_command_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_COMMAND,
            'repair_bridge_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_REPAIR_BRIDGE_SERVICE,
            'mutative_repair_executor_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_MUTATIVE_REPAIR_EXECUTOR_SERVICE,
            'patch_request_contract_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_PATCH_REQUEST_CONTRACT_SERVICE,
            'patch_proposal_gate_service_path' => AtlasAiProductCertificationService::PATH_PRODUCT_DELIVERY_PATCH_PROPOSAL_GATE_SERVICE,
            'controller_path' => AtlasAiProductCertificationService::PATH_AI_INTERACTION_CONTROLLER,
            'test_path' => AtlasAiProductCertificationService::PATH_AEDPDS_TEST,
            'doc_path' => AtlasAiProductCertificationService::PATH_AEDPDS_DOC,
        ]);
    }

    public function contextMemoryQualityCheck(): array
    {
        $certSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CONTEXT_QUALITY_SERVICE));
        $memorySource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_COGNITIVE_MEMORY_FABRIC_SERVICE));
        $commandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CONTEXT_QUALITY_COMMAND));
        $certTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CONTEXT_QUALITY_TEST));
        $memoryTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_COGNITIVE_MEMORY_FABRIC_TEST));
        $docSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CONTEXT_QUALITY_DOC));

        $certifiesAucriBlocks = str_contains($certSource, 'atlas.context.quality_certification.v1')
            && str_contains($certSource, 'aucri_runtime_enforcement')
            && str_contains($certSource, 'aucri_blocks_executed')
            && str_contains($certSource, 'target_score');
        $cognitiveMemoryPresent = str_contains($memorySource, 'atlas.aucri.cognitive_memory_fabric.v1')
            && str_contains($memorySource, 'must_keep_coverage')
            && str_contains($memorySource, 'raw_text_exposed');
        $commandPresent = str_contains($commandSource, 'atlas:context:quality-certify')
            && str_contains($commandSource, '--strict');
        $testsCover = str_contains($certTestSource, 'quality_score')
            && str_contains($certTestSource, 'aucri_runtime_enforcement')
            && str_contains($memoryTestSource, 'must_keep_coverage')
            && str_contains($memoryTestSource, 'raw_text_exposed');
        $docPresent = str_contains($docSource, 'atlas:context:quality-certify --json --strict')
            && str_contains($docSource, 'target=10');

        $passed = $certifiesAucriBlocks && $cognitiveMemoryPresent
            && $commandPresent && $testsCover && $docPresent;

        return $this->support->check('context_memory_quality', $passed, 'critical', [
            'context_quality_certifies_aucri_blocks' => $certifiesAucriBlocks,
            'cognitive_memory_fabric_present' => $cognitiveMemoryPresent,
            'command_present' => $commandPresent,
            'tests_cover_quality_and_memory' => $testsCover,
            'canonical_doc_present' => $docPresent,
            'certification_service_path' => AtlasAiProductCertificationService::PATH_CONTEXT_QUALITY_SERVICE,
            'memory_fabric_path' => AtlasAiProductCertificationService::PATH_COGNITIVE_MEMORY_FABRIC_SERVICE,
            'test_path' => AtlasAiProductCertificationService::PATH_CONTEXT_QUALITY_TEST,
        ]);
    }

    public function runtimeEfficiencyGovernorCheck(): array
    {
        $certSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_RUNTIME_EFFICIENCY_CERTIFICATION_SERVICE));
        $runtimeSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_RUNTIME_EFFICIENCY_SERVICE));
        $commandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_RUNTIME_EFFICIENCY_COMMAND));
        $testSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_RUNTIME_EFFICIENCY_TEST));
        $serviceTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_RUNTIME_EFFICIENCY_SERVICE_TEST));
        $docSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_RUNTIME_EFFICIENCY_DOC));

        $certifiesCorePaths = str_contains($certSource, 'fast_path_smoke')
            && str_contains($certSource, 'forge_path_smoke')
            && str_contains($certSource, 'blocked_path_smoke')
            && str_contains($certSource, 'self_optimization_smoke');
        $runtimeGoverns = str_contains($runtimeSource, 'context_minimum_pack')
            && str_contains($runtimeSource, 'layer_admissions')
            && str_contains($runtimeSource, 'recordOutcome')
            && str_contains($runtimeSource, 'persist');
        $commandPresent = str_contains($commandSource, 'atlas:runtime-efficiency:certify')
            && str_contains($commandSource, '--strict');
        $testsCover = str_contains($testSource, 'STATUS_PASSED')
            && str_contains($serviceTestSource, 'fast_path')
            && str_contains($serviceTestSource, 'forge_path')
            && str_contains($serviceTestSource, 'blocked_path')
            && str_contains($serviceTestSource, 'recordOutcome');
        $docPresent = str_contains($docSource, 'Atlas Runtime Efficiency Governor')
            && str_contains($docSource, 'recordOutcome');

        $passed = $certifiesCorePaths && $runtimeGoverns
            && $commandPresent && $testsCover && $docPresent;

        return $this->support->check('runtime_efficiency_governor', $passed, 'critical', [
            'certifies_core_paths' => $certifiesCorePaths,
            'runtime_governs_context_layers_and_outcome' => $runtimeGoverns,
            'command_present' => $commandPresent,
            'tests_cover_core_paths' => $testsCover,
            'canonical_doc_present' => $docPresent,
            'certification_service_path' => AtlasAiProductCertificationService::PATH_RUNTIME_EFFICIENCY_CERTIFICATION_SERVICE,
            'runtime_service_path' => AtlasAiProductCertificationService::PATH_RUNTIME_EFFICIENCY_SERVICE,
            'test_path' => AtlasAiProductCertificationService::PATH_RUNTIME_EFFICIENCY_TEST,
            'service_test_path' => AtlasAiProductCertificationService::PATH_RUNTIME_EFFICIENCY_SERVICE_TEST,
        ]);
    }

    public function aemorRuntimeCheck(): array
    {
        $certSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AEMOR_CERTIFICATION_SERVICE));
        $runtimeSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AEMOR_RUNTIME_SERVICE));
        $commandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AEMOR_CERTIFICATION_COMMAND));
        $testSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AEMOR_RUNTIME_TEST));
        $docSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AEMOR_DOC));

        $certifiesJudgment = str_contains($certSource, 'judgment_smoke')
            && str_contains($certSource, 'intelligence_outputs')
            && str_contains($certSource, 'claimPolicy');
        $runtimeClosesOutcome = str_contains($runtimeSource, 'openEpisode')
            && str_contains($runtimeSource, 'closeOutcome')
            && str_contains($runtimeSource, 'distill')
            && str_contains($runtimeSource, 'riskPredict');
        $commandPresent = str_contains($commandSource, 'atlas:aemor:certify')
            && str_contains($commandSource, '--strict');
        $testsCover = str_contains($testSource, 'close_outcome')
            && str_contains($testSource, 'risk_predict')
            && str_contains($testSource, 'replay');
        $docPresent = str_contains($docSource, 'Atlas Execution Memory')
            && str_contains($docSource, 'AEMOR');

        $passed = $certifiesJudgment && $runtimeClosesOutcome
            && $commandPresent && $testsCover && $docPresent;

        return $this->support->check('aemor_runtime', $passed, 'critical', [
            'certifies_judgment_and_intelligence_outputs' => $certifiesJudgment,
            'runtime_closes_outcome_and_replays' => $runtimeClosesOutcome,
            'command_present' => $commandPresent,
            'tests_cover_outcome_risk_replay' => $testsCover,
            'canonical_doc_present' => $docPresent,
            'certification_service_path' => AtlasAiProductCertificationService::PATH_AEMOR_CERTIFICATION_SERVICE,
            'runtime_service_path' => AtlasAiProductCertificationService::PATH_AEMOR_RUNTIME_SERVICE,
            'test_path' => AtlasAiProductCertificationService::PATH_AEMOR_RUNTIME_TEST,
        ]);
    }

    public function runtimeUxOperationalCheck(): array
    {
        $certSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_RUNTIME_UX_CERTIFICATION_SERVICE));
        $readinessSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_RUNTIME_READINESS_SERVICE));
        $testSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_RUNTIME_UX_TEST));
        $mobileContextSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_MOBILE_CONTEXT_SHEET));

        $certifiesAssistedUx = str_contains($certSource, 'assisted_execution_operational_ux')
            && str_contains($certSource, 'doctrine_signal_present')
            && str_contains($certSource, 'areg_aemor_signal_present');
        $readinessEmitsBundle = str_contains($readinessSource, 'atlas.ai.assisted_execution.operational_ux.v1')
            && str_contains($readinessSource, 'assistedExecutionOperationalState')
            && str_contains($readinessSource, "'assisted_execution' =>");
        $testsCover = str_contains($testSource, 'assisted_execution')
            && str_contains($testSource, 'doctrine_gate_status')
            && str_contains($testSource, 'aemor_feedback_status');
        $mobileRenders = str_contains($mobileContextSource, 'assistedExecution')
            && str_contains($mobileContextSource, 'doutrina')
            && str_contains($mobileContextSource, 'AEMOR');

        $passed = $certifiesAssistedUx && $readinessEmitsBundle
            && $testsCover && $mobileRenders;

        return $this->support->check('runtime_ux_operational', $passed, 'critical', [
            'certification_checks_assisted_execution_ux' => $certifiesAssistedUx,
            'readiness_emits_assisted_execution_bundle' => $readinessEmitsBundle,
            'tests_cover_assisted_execution_bundle' => $testsCover,
            'mobile_context_renders_operational_signals' => $mobileRenders,
            'certification_service_path' => AtlasAiProductCertificationService::PATH_RUNTIME_UX_CERTIFICATION_SERVICE,
            'readiness_service_path' => AtlasAiProductCertificationService::PATH_RUNTIME_READINESS_SERVICE,
            'test_path' => AtlasAiProductCertificationService::PATH_RUNTIME_UX_TEST,
        ]);
    }

    public function autonomousEvolutionLoopCheck(): array
    {
        $runtimeSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AAEL_RUNTIME_SERVICE));
        $certSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AAEL_CERTIFICATION_SERVICE));
        $commandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AAEL_COMMAND));
        $certCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AAEL_CERTIFY_COMMAND));
        $testSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AAEL_TEST));
        $certTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AAEL_CERTIFICATION_TEST));
        $docSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AAEL_DOC));

        $runtimeHasBridge = str_contains($runtimeSource, 'atlas.aael.assisted_execution_bridge.v1')
            && str_contains($runtimeSource, 'assistedExecutionBridge')
            && str_contains($runtimeSource, 'AtlasAiAssistedExecutionQualityService')
            && str_contains($runtimeSource, 'assisted_execution_quality_required');
        $certifiesBridge = str_contains($certSource, 'assistedExecutionBridgeSmoke')
            && str_contains($certSource, 'promotion_gate_status')
            && str_contains($certSource, 'aemor_feedback_status');
        $commandsPresent = str_contains($commandSource, 'atlas:aael')
            && str_contains($certCommandSource, 'atlas:aael:certify');
        $testsCover = str_contains($testSource, 'assisted_execution_quality')
            && str_contains($testSource, 'aedpds_gate_status')
            && str_contains($certTestSource, 'certification_passes_all_checks');
        $docPresent = str_contains($docSource, 'atlas.aael.assisted_execution_bridge.v1')
            && str_contains($docSource, 'AAEQ assisted-execution bridge');

        $passed = $runtimeHasBridge && $certifiesBridge
            && $commandsPresent && $testsCover && $docPresent;

        return $this->support->check('autonomous_evolution_loop', $passed, 'critical', [
            'runtime_has_assisted_execution_bridge' => $runtimeHasBridge,
            'certification_checks_bridge' => $certifiesBridge,
            'commands_present' => $commandsPresent,
            'tests_cover_bridge_and_certification' => $testsCover,
            'canonical_doc_present' => $docPresent,
            'runtime_service_path' => AtlasAiProductCertificationService::PATH_AAEL_RUNTIME_SERVICE,
            'certification_service_path' => AtlasAiProductCertificationService::PATH_AAEL_CERTIFICATION_SERVICE,
            'test_path' => AtlasAiProductCertificationService::PATH_AAEL_TEST,
            'doc_path' => AtlasAiProductCertificationService::PATH_AAEL_DOC,
        ]);
    }
}
