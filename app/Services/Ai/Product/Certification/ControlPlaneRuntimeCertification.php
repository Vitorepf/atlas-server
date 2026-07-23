<?php

namespace App\Services\Ai\Product\Certification;

use App\Services\Ai\Product\AtlasAiProductCertificationService;

/**
 * Atlas AI · Product Certification — Control Plane Runtime section.
 *
 * Checks 13-20: the governed runtime control plane is wired — Desktop control
 * plane UX, governed external execution, agent control plane standard, agentic
 * workcell, autonomous company runtime, capability-usage evolution, verified
 * context execution loop, code-intelligence automatic gate.
 *
 * Bodies moved verbatim from AtlasAiProductCertificationService; the only
 * rewrites are shared-helper calls and const references
 * (PATH_* -> AtlasAiProductCertificationService::PATH_*).
 */
final class ControlPlaneRuntimeCertification
{
    public function __construct(private readonly CertificationSupport $support)
    {
    }

    /* ---------------------------------------------------------------- */
    /* Check 13 · Desktop Control Plane exposes runtime governance state */
    /* ---------------------------------------------------------------- */

    public function desktopControlPlaneRuntimeUxCheck(): array
    {
        $surfaceSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_CONTROL_PLANE_SURFACE));
        $cssSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_CONTROL_PLANE_CSS));

        $readsUnsafeExternal = str_contains($surfaceSource, 'external_execution_unsafe_enabled');
        $readsReceiptGaps = str_contains($surfaceSource, 'external_execution_missing_receipt_bindings');
        $rendersUnsafeMetric = str_contains($surfaceSource, 'unsafe enabled');
        $rendersReceiptMetric = str_contains($surfaceSource, 'receipt gaps');
        $rendersSignatureCoverage = str_contains($surfaceSource, 'Signature coverage');
        $rendersBlockedPolicy = str_contains($surfaceSource, 'blocked by default')
            && str_contains($surfaceSource, 'manual handoff only');
        $hasGovernanceStrip = str_contains($surfaceSource, 'cp-governance-strip')
            && str_contains($cssSource, '.cp-governance-strip');

        $passed = $readsUnsafeExternal && $readsReceiptGaps && $rendersUnsafeMetric
            && $rendersReceiptMetric && $rendersSignatureCoverage
            && $rendersBlockedPolicy && $hasGovernanceStrip;

        return $this->support->check('desktop_control_plane_runtime_governance_ux', $passed, 'critical', [
            'reads_external_execution_unsafe_enabled' => $readsUnsafeExternal,
            'reads_external_execution_missing_receipt_bindings' => $readsReceiptGaps,
            'renders_unsafe_execution_metric' => $rendersUnsafeMetric,
            'renders_receipt_gap_metric' => $rendersReceiptMetric,
            'renders_signature_coverage' => $rendersSignatureCoverage,
            'renders_blocked_by_default_policy' => $rendersBlockedPolicy,
            'governance_strip_styled' => $hasGovernanceStrip,
            'surface_path' => AtlasAiProductCertificationService::PATH_DESKTOP_CONTROL_PLANE_SURFACE,
            'css_path' => AtlasAiProductCertificationService::PATH_DESKTOP_CONTROL_PLANE_CSS,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 14 · External execution remains governed, not free-running */
    /* ---------------------------------------------------------------- */

    public function governedExternalExecutionCheck(): array
    {
        $serviceSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CONTROL_PLANE_SERVICE));
        $testSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CONTROL_PLANE_TEST));

        $tracksUnsafeExternal = str_contains($serviceSource, 'external_execution_unsafe_enabled')
            && str_contains($serviceSource, 'unsafe_external_execution_enabled');
        $tracksReceiptBindings = str_contains($serviceSource, 'external_execution_missing_receipt_bindings')
            && str_contains($serviceSource, 'missing_receipt_binding_count');
        $blocksUnsafeRuntime = str_contains($serviceSource, 'unsafe_external_execution_blocks_runtime_status')
            && str_contains($serviceSource, 'externalExecutionBlocker');
        $manualHandoffOnly = str_contains($serviceSource, 'manual_handoff_only_even_after_approval');
        $operatorQueuePolicy = str_contains($serviceSource, 'pending_operator_review_is_operator_queue_not_system_failure');
        $testsPendingApproval = str_contains($testSource, 'test_external_execution_pending_approval_is_governed_without_system_blocker');
        $testsUnsafeBlocker = str_contains($testSource, 'test_external_execution_enabled_without_policy_blocks_runtime_report');

        $passed = $tracksUnsafeExternal && $tracksReceiptBindings && $blocksUnsafeRuntime
            && $manualHandoffOnly && $operatorQueuePolicy
            && $testsPendingApproval && $testsUnsafeBlocker;

        return $this->support->check('governed_external_execution_control_plane', $passed, 'critical', [
            'tracks_unsafe_external_execution' => $tracksUnsafeExternal,
            'tracks_missing_receipt_bindings' => $tracksReceiptBindings,
            'unsafe_execution_blocks_runtime_status' => $blocksUnsafeRuntime,
            'manual_handoff_only_policy_present' => $manualHandoffOnly,
            'pending_approval_is_operator_queue_policy_present' => $operatorQueuePolicy,
            'pending_approval_test_present' => $testsPendingApproval,
            'unsafe_execution_blocker_test_present' => $testsUnsafeBlocker,
            'service_path' => AtlasAiProductCertificationService::PATH_CONTROL_PLANE_SERVICE,
            'test_path' => AtlasAiProductCertificationService::PATH_CONTROL_PLANE_TEST,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 14 · Agent Control Plane is the governed agent runtime base */
    /* ---------------------------------------------------------------- */

    public function agentControlPlaneRuntimeStandardCheck(): array
    {
        $orchestratorSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR));
        $multiAgentSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_CERTIFICATION));
        $taskPacketSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_TASK_PACKET_BUILDER));
        $leaseSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_REPOSITORY));
        $orchestratorTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR_TEST));
        $multiAgentTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_TEST));
        $taskPacketTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_TASK_PACKET_TEST));
        $leaseTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_TEST));

        $orchestratesQueueAndLeases = str_contains($orchestratorSource, 'AgentControlPlaneTaskPacketBuilder')
            && str_contains($orchestratorSource, 'AgentControlPlaneClaimLeaseRepository')
            && str_contains($orchestratorSource, 'claimNext');
        $multiAgentLoopCertified = str_contains($multiAgentSource, 'DEFAULT_AGENT_COUNT')
            && str_contains($multiAgentSource, 'n_agents_received_distinct_tasks')
            && str_contains($multiAgentSource, 'write_set_no_collision');
        $taskPacketsHaveAcceptanceAndEvidence = str_contains($taskPacketSource, 'acceptance_criteria')
            && str_contains($taskPacketSource, 'evidence_requirements')
            && str_contains($taskPacketSource, 'task_packet_hash');
        $leasesGovernOwnership = str_contains($leaseSource, 'one ACTIVE lease per task_packet_id')
            && str_contains($leaseSource, 'dispatch_allowed')
            && str_contains($leaseSource, 'RECEIPT_CLAIM_ACQUIRED');
        $testsOrchestrator = str_contains($orchestratorTestSource, 'AgentControlPlaneTaskQueueOrchestrator');
        $testsMultiAgent = str_contains($multiAgentTestSource, 'MultiAgentLoopCertification')
            || str_contains($multiAgentTestSource, 'multi_agent_loop');
        $testsTaskPackets = str_contains($taskPacketTestSource, 'task_packet_hash')
            && str_contains($taskPacketTestSource, 'dispatch_allowed');
        $testsLeases = str_contains($leaseTestSource, 'runtime_execution_allowed')
            && str_contains($leaseTestSource, 'dispatch_allowed')
            && str_contains($leaseTestSource, 'lease_receipts_local');

        $passed = $orchestratesQueueAndLeases && $multiAgentLoopCertified
            && $taskPacketsHaveAcceptanceAndEvidence && $leasesGovernOwnership
            && $testsOrchestrator && $testsMultiAgent && $testsTaskPackets
            && $testsLeases;

        return $this->support->check('agent_control_plane_runtime_standard', $passed, 'critical', [
            'orchestrates_task_queue_and_claim_leases' => $orchestratesQueueAndLeases,
            'multi_agent_loop_certified' => $multiAgentLoopCertified,
            'task_packets_have_acceptance_and_evidence' => $taskPacketsHaveAcceptanceAndEvidence,
            'leases_govern_ownership_and_disable_dispatch' => $leasesGovernOwnership,
            'orchestrator_test_present' => $testsOrchestrator,
            'multi_agent_loop_test_present' => $testsMultiAgent,
            'task_packet_test_present' => $testsTaskPackets,
            'claim_lease_test_present' => $testsLeases,
            'orchestrator_path' => AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR,
            'multi_agent_certification_path' => AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_CERTIFICATION,
            'task_packet_builder_path' => AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_TASK_PACKET_BUILDER,
            'claim_lease_repository_path' => AtlasAiProductCertificationService::PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_REPOSITORY,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 15 · Agentic Workcell is the operational workforce layer */
    /* ---------------------------------------------------------------- */

    public function agenticWorkcellRuntimeCheck(): array
    {
        $runtimeSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_RUNTIME_SERVICE));
        $certSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_CERTIFICATION_SERVICE));
        $commandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_COMMAND));
        $certCommandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_CERTIFY_COMMAND));
        $runtimeTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_RUNTIME_TEST));
        $certTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_CERTIFICATION_TEST));
        $docSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_DOC));

        $runtimeEmitsOperationalRoster = str_contains($runtimeSource, 'role_roster')
            && str_contains($runtimeSource, 'task_graph')
            && str_contains($runtimeSource, 'context_packs')
            && str_contains($runtimeSource, 'execution_schedule')
            && str_contains($runtimeSource, 'verification_plan');
        $runtimeLearnsAndSurfacesControlPlane = str_contains($runtimeSource, 'closeOutcome')
            && str_contains($runtimeSource, 'compileOrgPattern')
            && str_contains($runtimeSource, 'controlPlane')
            && str_contains($runtimeSource, 'objective_hash')
            && str_contains($runtimeSource, 'claimPolicy');
        $certifiesTopologies = str_contains($certSource, 'forgeCrewSmoke')
            && str_contains($certSource, 'researchMapReduceSmoke')
            && str_contains($certSource, 'redBlueSmoke')
            && str_contains($certSource, 'toolBuilderSmoke')
            && str_contains($certSource, 'outcomeLearningSmoke')
            && str_contains($certSource, 'controlPlaneSmoke');
        $commandsPresent = str_contains($commandSource, 'atlas:agentic-workcell')
            && str_contains($commandSource, 'design|event|outcome|control-plane')
            && str_contains($certCommandSource, 'atlas:agentic-workcell:certify')
            && str_contains($certCommandSource, '--strict');
        $testsCoverRuntime = str_contains($runtimeTestSource, 'test_forge_objective_creates_milestone_crew_with_context_isolation')
            && str_contains($runtimeTestSource, 'test_close_outcome_compiles_org_pattern_and_control_plane_hides_objective')
            && str_contains($runtimeTestSource, 'test_external_side_effect_is_blocked_before_agent_execution')
            && str_contains($certTestSource, 'test_certification_passes_with_all_canonical_artifacts')
            && str_contains($certTestSource, 'test_commands_design_control_plane_and_certify_json');
        $docPresent = str_contains($docSource, 'Atlas Agentic Workcell Runtime')
            && str_contains($docSource, 'AAWR')
            && str_contains($docSource, 'Atlas Cognitive Workcell')
            && str_contains($docSource, 'AtlasAgenticWorkcellRuntimeService');

        $passed = $runtimeEmitsOperationalRoster && $runtimeLearnsAndSurfacesControlPlane
            && $certifiesTopologies && $commandsPresent && $testsCoverRuntime && $docPresent;

        return $this->support->check('agentic_workcell_runtime', $passed, 'critical', [
            'runtime_emits_operational_roster' => $runtimeEmitsOperationalRoster,
            'runtime_learns_and_surfaces_control_plane' => $runtimeLearnsAndSurfacesControlPlane,
            'certifies_topologies_and_outcome_learning' => $certifiesTopologies,
            'commands_present' => $commandsPresent,
            'tests_cover_runtime_and_certification' => $testsCoverRuntime,
            'canonical_doc_present' => $docPresent,
            'runtime_service_path' => AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_RUNTIME_SERVICE,
            'certification_service_path' => AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_CERTIFICATION_SERVICE,
            'runtime_test_path' => AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_RUNTIME_TEST,
            'certification_test_path' => AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_CERTIFICATION_TEST,
            'doc_path' => AtlasAiProductCertificationService::PATH_AGENTIC_WORKCELL_DOC,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 16 · Internal autonomous software company runtime claim gate */
    /* ---------------------------------------------------------------- */

    public function autonomousCompanyRuntimeCheck(): array
    {
        $serviceSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_ENGINEERING_COMPANY_SERVICE));
        $featureTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_ENGINEERING_COMPANY_FEATURE_TEST));
        $unitTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_ENGINEERING_COMPANY_UNIT_TEST));

        $certifiesRoleTaskPackets = str_contains($serviceSource, 'all_roles_have_agent_control_plane_task_packets')
            && str_contains($serviceSource, 'allRolesHaveAgentTaskPackets');
        $claimsInternalCompany = str_contains($serviceSource, 'ready_to_claim_autonomous_software_company');
        $blocksExternalSuperiority = str_contains($serviceSource, "'ready_to_claim_external_superiority' => false")
            || str_contains($serviceSource, '"ready_to_claim_external_superiority" => false');
        $blocksBenchmarkClaim = str_contains($serviceSource, "'external_benchmark_executed' => false")
            || str_contains($serviceSource, '"external_benchmark_executed" => false');
        $featureTestCoversClaim = str_contains($featureTestSource, 'ready_to_claim_autonomous_software_company')
            && str_contains($featureTestSource, 'all_roles_have_agent_control_plane_task_packets');
        $unitTestCoversClaim = str_contains($unitTestSource, 'ready_to_claim_autonomous_software_company')
            && str_contains($unitTestSource, 'all_roles_have_agent_control_plane_task_packets');

        $passed = $certifiesRoleTaskPackets && $claimsInternalCompany
            && $blocksExternalSuperiority && $blocksBenchmarkClaim
            && $featureTestCoversClaim && $unitTestCoversClaim;

        return $this->support->check('internal_autonomous_company_runtime_claim_gate', $passed, 'critical', [
            'certifies_all_roles_have_agent_task_packets' => $certifiesRoleTaskPackets,
            'internal_autonomous_company_claim_present' => $claimsInternalCompany,
            'external_superiority_claim_blocked' => $blocksExternalSuperiority,
            'external_benchmark_claim_blocked' => $blocksBenchmarkClaim,
            'feature_test_covers_claim_gate' => $featureTestCoversClaim,
            'unit_test_covers_claim_gate' => $unitTestCoversClaim,
            'service_path' => AtlasAiProductCertificationService::PATH_ENGINEERING_COMPANY_SERVICE,
            'feature_test_path' => AtlasAiProductCertificationService::PATH_ENGINEERING_COMPANY_FEATURE_TEST,
            'unit_test_path' => AtlasAiProductCertificationService::PATH_ENGINEERING_COMPANY_UNIT_TEST,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 16 · Capabilities are used, measured and improved by outcome */
    /* ---------------------------------------------------------------- */

    public function capabilityEvolutionLoopCheck(): array
    {
        // GOD-DEBULK 3b: IntelligenceFactory quarantined (archive/app/Services/Ai/IntelligenceFactory,
        // blueprint 91c334a27 §2.2). The IF-source assertions (factory records usage / certifies
        // registry) and the AEMOR-creates-candidate assertions were removed with it — AEMOR now
        // degrades that path to `skipped`. ControlPlane keeps tracking the surviving tables/counters.
        $controlPlaneSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CONTROL_PLANE_SERVICE));
        $controlPlaneTestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CONTROL_PLANE_TEST));

        $controlPlaneTracksUsage = str_contains($controlPlaneSource, 'intelligence_factory_capability_used_events')
            && str_contains($controlPlaneSource, 'capability_used_events');
        $controlPlaneTracksEvolution = str_contains($controlPlaneSource, 'intelligence_factory_evolution_events')
            && str_contains($controlPlaneSource, 'evolution_events_total');
        $testsControlPlaneCounters = str_contains($controlPlaneTestSource, 'intelligence_factory_capability_used_events')
            && str_contains($controlPlaneTestSource, 'intelligence_factory_evolution_events');

        $passed = $controlPlaneTracksUsage && $controlPlaneTracksEvolution
            && $testsControlPlaneCounters;

        return $this->support->check('capability_usage_and_evolution_loop', $passed, 'critical', [
            'control_plane_tracks_capability_used_events' => $controlPlaneTracksUsage,
            'control_plane_tracks_evolution_events' => $controlPlaneTracksEvolution,
            'control_plane_tests_cover_counters' => $testsControlPlaneCounters,
            'intelligence_factory_quarantined' => 'archive/app/Services/Ai/IntelligenceFactory',
            'control_plane_service_path' => AtlasAiProductCertificationService::PATH_CONTROL_PLANE_SERVICE,
            'aemor_service_path' => AtlasAiProductCertificationService::PATH_AEMOR_RUNTIME_SERVICE,
        ]);
    }

    public function verifiedContextExecutionLoopCheck(): array
    {
        $serviceSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AVCEL_SERVICE));
        $commandSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AVCEL_COMMAND));
        $testSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AVCEL_TEST));
        $docSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AVCEL_DOC));

        $serviceConnectsCoreRuntimes = str_contains($serviceSource, 'AtlasContextCacheCompilerRuntimeService')
            && str_contains($serviceSource, 'AtlasTokenEconomyRuntimeService')
            && str_contains($serviceSource, 'AtlasLocalVerificationEngineService')
            && str_contains($serviceSource, 'AtlasAemorRuntimeService');
        $serviceDeclaresReadOnlyPolicy = str_contains($serviceSource, "'providers_invoked' => false")
            && str_contains($serviceSource, "'commands_executed' => false")
            && str_contains($serviceSource, "'writes' => false");
        $serviceDefinesEightStages = str_contains($serviceSource, "'context_compile'")
            && str_contains($serviceSource, "'must_keep_guard'")
            && str_contains($serviceSource, "'outcome_memory_candidate'");
        $commandPresent = str_contains($commandSource, 'atlas:verified-context-execution')
            && str_contains($commandSource, 'certify|shadow');
        $testCoversCoreInvariants = str_contains($testSource, 'test_shadow_builds_eight_stage_read_only_verified_context_execution_loop')
            && str_contains($testSource, 'test_shadow_turns_failure_log_into_repair_strategy_without_exposing_secret')
            && str_contains($testSource, 'test_certification_passes_and_proves_core_invariants');
        $docPresent = str_contains($docSource, 'runtime_acronym: AVCEL')
            && str_contains($docSource, 'Atlas Verified Context Execution Loop');

        $passed = $serviceConnectsCoreRuntimes && $serviceDeclaresReadOnlyPolicy
            && $serviceDefinesEightStages && $commandPresent
            && $testCoversCoreInvariants && $docPresent;

        return $this->support->check('verified_context_execution_loop', $passed, 'critical', [
            'service_connects_context_cache_token_economy_alve_aemor' => $serviceConnectsCoreRuntimes,
            'service_declares_read_only_policy' => $serviceDeclaresReadOnlyPolicy,
            'service_defines_eight_stage_loop' => $serviceDefinesEightStages,
            'command_present' => $commandPresent,
            'tests_cover_core_invariants' => $testCoversCoreInvariants,
            'canonical_doc_present' => $docPresent,
            'service_path' => AtlasAiProductCertificationService::PATH_AVCEL_SERVICE,
            'command_path' => AtlasAiProductCertificationService::PATH_AVCEL_COMMAND,
            'test_path' => AtlasAiProductCertificationService::PATH_AVCEL_TEST,
            'doc_path' => AtlasAiProductCertificationService::PATH_AVCEL_DOC,
        ]);
    }

    public function codeIntelligenceAutomaticGateCheck(): array
    {
        $serviceSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CODE_INTELLIGENCE_GATE_SERVICE));
        $commandSource = $this->support->source($this->support->repoPath('app/Console/Commands/AtlasEngineeringKnowledgeCommand.php'));
        $programmingGateSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_PROGRAMMING_CODE_INTELLIGENCE_GATE));
        $sessionBootstrapSource = $this->support->source($this->support->repoPath('app/Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php'));
        $testSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CODE_INTELLIGENCE_GATE_TEST));
        $docSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_CODE_INTELLIGENCE_DOC));

        $serviceFailClosed = str_contains($serviceSource, 'blocks_dev_forge_when_blocked')
            && str_contains($serviceSource, 'stale_index_allowed')
            && str_contains($serviceSource, 'context_can_be_trusted_when_blocked');
        $serviceCoversConsumers = str_contains($serviceSource, "'forge'")
            && str_contains($serviceSource, "'atlas_dev'")
            && str_contains($serviceSource, "'acrui'")
            && str_contains($serviceSource, "'software_twin'")
            && str_contains($serviceSource, "'avcel'");
        $commandWired = str_contains($commandSource, "'code-gate'")
            && str_contains($commandSource, '--auto-refresh')
            && str_contains($commandSource, '--strict');
        $devGateWired = str_contains($programmingGateSource, 'AtlasCodeIntelligenceAutomaticGateService')
            && str_contains($programmingGateSource, 'code_intelligence_automatic_gate_blocked');
        $bootstrapWired = str_contains($sessionBootstrapSource, 'code_intelligence_automatic_gate');
        $testsCover = str_contains($testSource, 'test_stale_index_blocks_when_strict_freshness_is_enabled')
            && str_contains($testSource, 'consumer_count');
        $docCovers = str_contains($docSource, 'code-gate --auto-refresh --strict --json')
            && str_contains($docSource, 'Atlas Dev, Forge, ACRUI, Software Twin e AVCEL');

        $passed = $serviceFailClosed && $serviceCoversConsumers && $commandWired
            && $devGateWired && $bootstrapWired && $testsCover && $docCovers;

        return $this->support->check('code_intelligence_automatic_gate', $passed, 'critical', [
            'service_fail_closed' => $serviceFailClosed,
            'service_covers_required_consumers' => $serviceCoversConsumers,
            'command_wired' => $commandWired,
            'atlas_dev_gate_wired' => $devGateWired,
            'session_bootstrap_wired' => $bootstrapWired,
            'tests_cover_stale_and_consumers' => $testsCover,
            'doc_covers_gate' => $docCovers,
            'service_path' => AtlasAiProductCertificationService::PATH_CODE_INTELLIGENCE_GATE_SERVICE,
            'test_path' => AtlasAiProductCertificationService::PATH_CODE_INTELLIGENCE_GATE_TEST,
            'doc_path' => AtlasAiProductCertificationService::PATH_CODE_INTELLIGENCE_DOC,
        ]);
    }
}
