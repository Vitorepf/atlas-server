<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

/**
 * Atlas AI · Product Certification (E2E read-model).
 *
 * Single source of truth for whether the Atlas AI product (Mobile + Desktop
 * + Server + Forge) is wired end-to-end on the canonical runtime. Combines
 * file-inspection evidence across the four surfaces with the cross-surface
 * canon: Hyperflow V2 entry, `/ai/interactions` rich_input preservation,
 * Universal Composer canon, Presentation Contract on both surfaces,
 * Context/Trace/Audit consumption, anti-regression routing tests.
 *
 * What this cert does NOT do:
 *   - It does NOT invoke any provider, run rivals, or benchmark.
 *   - It does NOT execute external_rivals_certification or unlock it.
 *   - It does NOT replicate Hyperflow Certification (which guards the
 *     "Claude Code/Codex replacement" claim) — this cert is product-level
 *     plumbing, not a superiority claim.
 *   - It does NOT write to any persistent store.
 *
 * Status semantics:
 *   - `ready`    — every required invariant has positive evidence.
 *   - `partial`  — at least one non-critical check failed (`severity=warn`).
 *   - `blocked`  — at least one critical check failed (`severity=critical`).
 *
 * Hash convention (mirrors `MissionCanonicalHash`):
 *   `certification_hash = sha256(canonical_json(payload \ generated_at))`
 *   so the same tree produces the same hash regardless of when it ran.
 */
class AtlasAiProductCertificationService
{
    public const SCHEMA_VERSION = 'atlas.ai.product_certification.v1';

    // Canonical repo-relative paths the cert inspects (single source of truth).
    public const PATH_HYPERFLOW_ENTRY = 'app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php';

    public const PATH_AI_INTERACTION_CONTROLLER = 'app/Http/Controllers/AiInteractionController.php';

    public const PATH_FORGE_INTAKE_SERVICE = 'app/Services/Ai/Programming/Forge/ForgeIntakeService.php';

    public const PATH_FORGE_WORK_CONTROLLER = 'app/Http/Controllers/AtlasCodeWorkController.php';

    public const PATH_STORE_AI_INTERACTION_REQUEST = 'app/Http/Requests/StoreAiInteractionRequest.php';

    public const PATH_SPECIALIST_FLOWS_READINESS = 'app/Services/Ai/RouterRuntime/AtlasHyperflowSpecialistFlowsReadinessService.php';

    public const PATH_FLOW_ROUTER = 'app/Services/Ai/RouterRuntime/FlowRouterService.php';

    public const PATH_ROUTER_CANON = 'app/Services/Ai/RouterRuntime/RouterRuntimeCanon.php';

    public const PATH_CANON_PACKAGE = 'packages/atlas-rich-input-canon/src/index.ts';

    public const PATH_CANON_TYPES = 'packages/atlas-rich-input-canon/src/types.ts';

    // ponytail: tracks the atlas-ai client surface. The R2 runbook split it out of the
    // monolithic client.ts into atlasAi.ts (client.ts now re-exports it via `export * from
    // './atlasAi'`). Repoint if that surface moves again.
    public const PATH_MOBILE_CANON_IMPORT = 'atlas-app/lib/api/atlasAi.ts';

    public const PATH_MOBILE_RICH_INPUT_BARREL = 'atlas-app/lib/richInput/sourceManifest.ts';

    public const PATH_MOBILE_RICH_INPUT_TYPES = 'atlas-app/lib/richInput/types.ts';

    public const PATH_MOBILE_PRESENTATION = 'atlas-app/lib/atlasAi/presentationContract.ts';

    public const PATH_MOBILE_TURN_MODEL = 'atlas-app/components/sheets/atlas-ai/AtlasAiTurnModel.ts';

    public const PATH_MOBILE_CONTEXT_SHEET = 'atlas-app/components/sheets/atlas-ai/AtlasAiContextSheet.tsx';

    public const PATH_DESKTOP_RICH_INPUT_BARREL = 'atlas-desktop/apps/desktop/src/lib/rich-input/index.ts';

    public const PATH_DESKTOP_RICH_INPUT_TYPES = 'atlas-desktop/apps/desktop/src/lib/rich-input/types.ts';

    public const PATH_DESKTOP_PRESENTATION = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/presentationContract.ts';

    public const PATH_DESKTOP_CONVERSATION = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiConversation.tsx';

    public const PATH_DESKTOP_CONTEXT_PANEL = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiContextPanel.tsx';

    public const PATH_DESKTOP_RESPONSE_AUDIT = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiResponseAudit.tsx';

    public const PATH_DESKTOP_USE_HYPERFLOW = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/useHyperflowRuntime.ts';

    public const PATH_DESKTOP_ANTIREGRESSION = 'tests/Feature/Ai/AtlasAiDesktopHyperflowAntiRegressionTest.php';

    public const PATH_DESKTOP_INTEGRATION_TEST = 'tests/Feature/Ai/RouterRuntime/AtlasAiInteractionHyperflowEntryTest.php';

    public const PATH_DESKTOP_RICH_INPUT_TEST = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/__tests__/richInputPayloadIntegration.test.ts';

    public const PATH_DESKTOP_PRODUCT_TRIP_TEST = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/__tests__/desktopRuntimeFinalCertification.test.ts';

    public const PATH_FORGE_RICH_INPUT_TEST = 'tests/Feature/AtlasCode/AtlasCodeWorkRichInputTest.php';

    public const PATH_FORGE_INTAKE_TEST = 'tests/Feature/Ai/Programming/Forge/ForgeIntakeServiceTest.php';

    public const PATH_CONTROL_PLANE_SERVICE = 'app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php';

    public const PATH_CONTROL_PLANE_TEST = 'tests/Feature/Ai/ControlPlane/AtlasAiControlPlaneServiceTest.php';

    public const PATH_DESKTOP_CONTROL_PLANE_SURFACE = 'atlas-desktop/apps/desktop/src/surfaces/control-plane/ControlPlaneSurface.tsx';

    public const PATH_DESKTOP_CONTROL_PLANE_CSS = 'atlas-desktop/apps/desktop/src/surfaces/control-plane/control-plane.css';

    public const PATH_ENGINEERING_COMPANY_SERVICE = 'app/Services/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeService.php';

    public const PATH_ENGINEERING_COMPANY_FEATURE_TEST = 'tests/Feature/Ai/AtlasRealEngineeringCompanyRuntimeTest.php';

    public const PATH_ENGINEERING_COMPANY_UNIT_TEST = 'tests/Unit/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeServiceTest.php';

    public const PATH_AEMOR_RUNTIME_SERVICE = 'app/Services/Ai/Aemor/AtlasAemorRuntimeService.php';

    public const PATH_AEMOR_RUNTIME_TEST = 'tests/Feature/Ai/Aemor/AtlasAemorRuntimeServiceTest.php';

    // GOD-DEBULK 3b: PATH_INTELLIGENCE_FACTORY_RUNTIME_SERVICE / _CERTIFICATION_SERVICE removed —
    // IntelligenceFactory quarantined to archive/app/Services/Ai/IntelligenceFactory (blueprint
    // 91c334a27 §2.2); certification no longer pins the archived sources. Models/tables stay live.

    public const PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR = 'app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php';

    public const PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_CERTIFICATION = 'app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneMultiAgentLoopCertificationService.php';

    public const PATH_AGENT_CONTROL_PLANE_TASK_PACKET_BUILDER = 'app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketBuilder.php';

    public const PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_REPOSITORY = 'app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneClaimLeaseRepository.php';

    public const PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR_TEST = 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskQueueOrchestratorTest.php';

    public const PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_TEST = 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneMultiAgentLoopCertificationTest.php';

    public const PATH_AGENT_CONTROL_PLANE_TASK_PACKET_TEST = 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskPacketBuilderTest.php';

    public const PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_TEST = 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneClaimLeaseRepositoryTest.php';

    public const PATH_AGENTIC_WORKCELL_RUNTIME_SERVICE = 'app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php';

    public const PATH_AGENTIC_WORKCELL_CERTIFICATION_SERVICE = 'app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellCertificationService.php';

    public const PATH_AGENTIC_WORKCELL_COMMAND = 'app/Console/Commands/AtlasAgenticWorkcellCommand.php';

    public const PATH_AGENTIC_WORKCELL_CERTIFY_COMMAND = 'app/Console/Commands/AtlasAgenticWorkcellCertifyCommand.php';

    public const PATH_AGENTIC_WORKCELL_RUNTIME_TEST = 'tests/Feature/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeServiceTest.php';

    public const PATH_AGENTIC_WORKCELL_CERTIFICATION_TEST = 'tests/Feature/Ai/AgenticWorkcell/AtlasAgenticWorkcellCertificationServiceTest.php';

    public const PATH_AGENTIC_WORKCELL_DOC = 'docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md';

    public const PATH_AVCEL_SERVICE = 'app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php';

    public const PATH_AVCEL_COMMAND = 'app/Console/Commands/AtlasVerifiedContextExecutionLoopCommand.php';

    public const PATH_AVCEL_TEST = 'tests/Feature/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopServiceTest.php';

    public const PATH_AVCEL_DOC = 'docs/engineering-knowledge-base/atlas-verified-context-execution-loop.md';

    public const PATH_CODE_INTELLIGENCE_GATE_SERVICE = 'app/Services/Engineering/AtlasCodeIntelligenceAutomaticGateService.php';

    public const PATH_CODE_INTELLIGENCE_GATE_TEST = 'tests/Feature/Engineering/AtlasCodeIntelligenceAutomaticGateServiceTest.php';

    public const PATH_CODE_INTELLIGENCE_DOC = 'docs/engineering-knowledge-base/code-intelligence.md';

    public const PATH_PROGRAMMING_CODE_INTELLIGENCE_GATE = 'app/Services/Ai/Programming/Governance/Gates/ProgrammingCodeIntelligenceGate.php';

    public const PATH_PROGRAMMING_ORCHESTRATOR = 'app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php';

    public const PATH_PROGRAMMING_ORCHESTRATOR_TEST = 'tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php';

    public const PATH_PROGRAMMING_FRONTEND_DOC = 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md';

    public const PATH_ASSISTED_EXECUTION_SERVICE = 'app/Services/Ai/Product/AtlasAiAssistedExecutionQualityService.php';

    public const PATH_ASSISTED_EXECUTION_DOC = 'docs/engineering-knowledge-base/atlas-ai-assisted-execution-quality.md';

    public const PATH_ASSISTED_EXECUTION_TEST = 'tests/Feature/Ai/Product/AtlasAiAssistedExecutionQualityServiceTest.php';

    public const PATH_PRODUCT_TRUTH_COMPILER_SERVICE = 'app/Services/Ai/Product/AtlasProductTruthCompilerService.php';

    public const PATH_PRODUCT_DELIVERY_RUNTIME_SERVICE = 'app/Services/Ai/Product/AtlasAutonomousProductDeliveryRuntimeService.php';

    public const PATH_PRODUCT_FALSIFICATION_SERVICE = 'app/Services/Ai/Product/AtlasProductFalsificationProofRuntimeService.php';

    public const PATH_PRODUCT_DELIVERY_ENFORCEMENT_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryEnforcementService.php';

    public const PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryOutcomeMemoryService.php';

    public const PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryRuntimeReceiptService.php';

    public const PATH_PRODUCT_TWIN_SIMULATION_SERVICE = 'app/Services/Ai/Product/AtlasProductTwinSimulationService.php';

    public const PATH_PRODUCT_TWIN_SIMULATE_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductTwinSimulateCommand.php';

    public const PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryRiskGovernorService.php';

    public const PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductDeliveryRiskGovernorCommand.php';

    public const PATH_PRODUCT_DELIVERY_CONTROL_PLANE_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryControlPlaneService.php';

    public const PATH_PRODUCT_DELIVERY_CONTROL_PLANE_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductDeliveryControlPlaneCommand.php';

    public const PATH_PRODUCT_RELEASE_GATE_SERVICE = 'app/Services/Ai/Product/AtlasProductReleaseGateService.php';

    public const PATH_PRODUCT_RELEASE_GATE_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductReleaseGateCommand.php';

    public const PATH_PRODUCT_PROVIDER_MEMORY_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryProviderMemoryFeedService.php';

    public const PATH_PRODUCT_PROVIDER_MEMORY_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductDeliveryProviderMemoryCommand.php';

    public const PATH_PRODUCT_POLICY_OPTIMIZER_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryPolicyOptimizerService.php';

    public const PATH_PRODUCT_POLICY_OPTIMIZER_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductDeliveryPolicyOptimizerCommand.php';

    public const PATH_PRODUCT_DELIVERY_MULTI_STEP_REPAIR_PLANNER_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryMultiStepRepairPlannerService.php';

    public const PATH_PRODUCT_DELIVERY_REPAIR_PLAN_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductDeliveryRepairPlanCommand.php';

    public const PATH_PRODUCT_DELIVERY_EVIDENCE_REPLAY_LAB_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryEvidenceReplayLabService.php';

    public const PATH_PRODUCT_DELIVERY_REPLAY_LAB_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductDeliveryReplayLabCommand.php';

    public const PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryDoctrineFitnessService.php';

    public const PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductDeliveryDoctrineFitnessCommand.php';

    public const PATH_PRODUCT_DELIVERY_REPAIR_BRIDGE_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryRepairBridgeService.php';

    public const PATH_PRODUCT_DELIVERY_MUTATIVE_REPAIR_EXECUTOR_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryMutativeRepairExecutorService.php';

    public const PATH_PRODUCT_DELIVERY_PATCH_REQUEST_CONTRACT_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryPatchRequestContractService.php';

    public const PATH_PRODUCT_DELIVERY_PATCH_REQUEST_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductDeliveryPatchRequestCommand.php';

    public const PATH_PRODUCT_DELIVERY_PATCH_PROPOSAL_GATE_SERVICE = 'app/Services/Ai/Product/AtlasProductDeliveryPatchProposalGateService.php';

    public const PATH_PRODUCT_DELIVERY_REPAIR_EXECUTE_COMMAND = 'app/Console/Commands/Ai/Product/AtlasProductDeliveryRepairExecuteCommand.php';

    public const PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_MODEL = 'app/Models/AtlasProductDeliveryOutcomeMemory.php';

    public const PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_MODEL = 'app/Models/AtlasProductDeliveryRuntimeReceipt.php';

    public const PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_MIGRATION = 'database/migrations/2026_05_22_171000_create_atlas_product_delivery_outcome_memories.php';

    public const PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_MIGRATION = 'database/migrations/2026_05_22_172000_create_atlas_product_delivery_runtime_receipts.php';

    public const PATH_AEDPDS_DOC = 'docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md';

    public const PATH_APFPR_DOC = 'docs/engineering-knowledge-base/atlas-product-falsification-proof-runtime.md';

    public const PATH_AEDPDS_TEST = 'tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php';

    public const PATH_CONTEXT_QUALITY_SERVICE = 'app/Services/Ai/Context/AtlasContextQualityCertificationService.php';

    public const PATH_CONTEXT_QUALITY_COMMAND = 'app/Console/Commands/AtlasContextQualityCertifyCommand.php';

    public const PATH_COGNITIVE_MEMORY_FABRIC_SERVICE = 'app/Services/Ai/Context/AtlasCognitiveMemoryFabricService.php';

    public const PATH_CONTEXT_QUALITY_TEST = 'tests/Feature/Ai/Context/ContextQualityCertificationTest.php';

    public const PATH_COGNITIVE_MEMORY_FABRIC_TEST = 'tests/Feature/Ai/Context/CognitiveMemoryFabricTest.php';

    public const PATH_CONTEXT_QUALITY_DOC = 'docs/engineering-knowledge-base/atlas-context-quality-certification-gate.md';

    public const PATH_RUNTIME_EFFICIENCY_CERTIFICATION_SERVICE = 'app/Services/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorCertificationService.php';

    public const PATH_RUNTIME_EFFICIENCY_SERVICE = 'app/Services/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorService.php';

    public const PATH_RUNTIME_EFFICIENCY_COMMAND = 'app/Console/Commands/AtlasRuntimeEfficiencyGovernorCertifyCommand.php';

    public const PATH_RUNTIME_EFFICIENCY_TEST = 'tests/Feature/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorCertificationServiceTest.php';

    public const PATH_RUNTIME_EFFICIENCY_SERVICE_TEST = 'tests/Feature/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorServiceTest.php';

    public const PATH_RUNTIME_EFFICIENCY_DOC = 'docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md';

    public const PATH_AEMOR_CERTIFICATION_SERVICE = 'app/Services/Ai/Aemor/AtlasAemorCertificationService.php';

    public const PATH_AEMOR_CERTIFICATION_COMMAND = 'app/Console/Commands/AtlasAemorCertifyCommand.php';

    public const PATH_AEMOR_DOC = 'docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md';

    public const PATH_RUNTIME_UX_CERTIFICATION_SERVICE = 'app/Services/Ai/Product/AtlasAiRuntimeUxCertificationService.php';

    public const PATH_RUNTIME_UX_TEST = 'tests/Feature/Ai/Product/AtlasAiRuntimeUxCertificationServiceTest.php';

    public const PATH_RUNTIME_READINESS_SERVICE = 'app/Services/Ai/RuntimeReadiness/AtlasAiRuntimeReadinessService.php';

    public const PATH_AAEL_RUNTIME_SERVICE = 'app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php';

    public const PATH_AAEL_CERTIFICATION_SERVICE = 'app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionCertificationService.php';

    public const PATH_AAEL_COMMAND = 'app/Console/Commands/AtlasAaelCommand.php';

    public const PATH_AAEL_CERTIFY_COMMAND = 'app/Console/Commands/AtlasAaelCertifyCommand.php';

    public const PATH_AAEL_TEST = 'tests/Feature/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopServiceTest.php';

    public const PATH_AAEL_CERTIFICATION_TEST = 'tests/Feature/Ai/AutonomousEvolution/AtlasAutonomousEvolutionCertificationServiceTest.php';

    public const PATH_AAEL_DOC = 'docs/engineering-knowledge-base/atlas-autonomous-evolution-loop.md';

    public function __construct(
        private readonly Certification\SurfaceCanonCertification $surfaceCanon,
        private readonly Certification\ControlPlaneRuntimeCertification $controlPlane,
        private readonly Certification\ExecutionDoctrineCertification $doctrine,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->surfaceCanon->hyperflowV2EntryCheck(),                // critical
            $this->surfaceCanon->aiInteractionsPreservesRichInputCheck(), // critical
            $this->surfaceCanon->universalComposerCanonCheck(),          // critical
            $this->surfaceCanon->mobileUsesCanonCheck(),                 // critical
            $this->surfaceCanon->desktopUsesCanonCheck(),                // critical
            $this->surfaceCanon->forgeAcceptsCanonCheck(),               // critical
            $this->surfaceCanon->presentationContractCheck(),            // critical
            $this->surfaceCanon->contextTraceAuditCheck(),               // critical
            $this->surfaceCanon->specialistFlowsRegisteredCheck(),       // critical
            $this->surfaceCanon->routingAntiRegressionCheck(),           // critical
            $this->surfaceCanon->forgeHashAndContextRefsCheck(),         // critical
            $this->surfaceCanon->noAttachmentPathStillWorksCheck(),      // warn (not blocker)
            $this->controlPlane->desktopControlPlaneRuntimeUxCheck(),     // critical
            $this->controlPlane->agentControlPlaneRuntimeStandardCheck(), // critical
            $this->controlPlane->agenticWorkcellRuntimeCheck(),            // critical
            $this->controlPlane->governedExternalExecutionCheck(),        // critical
            $this->controlPlane->autonomousCompanyRuntimeCheck(),         // critical
            $this->controlPlane->capabilityEvolutionLoopCheck(),          // critical
            $this->controlPlane->codeIntelligenceAutomaticGateCheck(),     // critical
            $this->controlPlane->verifiedContextExecutionLoopCheck(),      // critical
            $this->doctrine->frontendOperationalUnderstandingCheck(),  // critical
            $this->doctrine->assistedExecutionQualityCheck(),          // critical
            $this->doctrine->executionDoctrineProductDeliverySystemCheck(), // critical
            $this->doctrine->contextMemoryQualityCheck(),              // critical
            $this->doctrine->runtimeEfficiencyGovernorCheck(),          // critical
            $this->doctrine->aemorRuntimeCheck(),                       // critical
            $this->doctrine->runtimeUxOperationalCheck(),               // critical
            $this->doctrine->autonomousEvolutionLoopCheck(),            // critical
        ];

        $criticalFailed = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ($check['status'] ?? null) !== 'passed'
                && ($check['severity'] ?? 'critical') === 'critical',
        ));
        $warnFailed = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ($check['status'] ?? null) !== 'passed'
                && ($check['severity'] ?? 'critical') === 'warn',
        ));

        $status = match (true) {
            $criticalFailed !== [] => 'blocked',
            $warnFailed !== [] => 'partial',
            default => 'ready',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total' => count($checks),
                'passed' => count(array_filter($checks, static fn (array $c): bool => ($c['status'] ?? null) === 'passed')),
                'critical_failed' => count($criticalFailed),
                'warn_failed' => count($warnFailed),
            ],
            'assisted_execution_scorecard' => $this->assistedExecutionScorecard($checks),
            'agentic_workforce_scorecard' => $this->agenticWorkforceScorecard($checks),
            'frontend_operational_scorecard' => $this->frontendOperationalScorecard($checks),
            'checks' => $checks,
            'remaining_blockers' => array_map(
                static fn (array $check): string => (string) ($check['id'] ?? 'unknown_check'),
                $criticalFailed,
            ),
            'evidence_refs' => $this->evidenceRefs(),
            'claims' => [
                'declares_teos' => false,
                'declares_benchmark' => false,
                'declares_superiority' => false,
                'invokes_provider' => false,
                'runs_rivals' => false,
                'scope' => 'product_runtime_governance',
                'covers_desktop_runtime_ux' => true,
                'covers_agent_control_plane_runtime' => true,
                'covers_agentic_workcell_runtime' => true,
                'covers_external_execution_governance' => true,
                'covers_internal_autonomous_company_runtime' => true,
                'covers_capability_usage_evolution' => true,
                'covers_code_intelligence_automatic_gate' => true,
                'covers_verified_context_execution_loop' => true,
                'covers_frontend_operational_understanding' => true,
                'covers_assisted_execution_quality' => true,
                'covers_execution_doctrine_product_delivery_system' => true,
                'covers_context_memory_quality' => true,
                'covers_runtime_efficiency_governor' => true,
                'covers_aemor_runtime' => true,
                'covers_runtime_ux_operational' => true,
                'covers_autonomous_evolution_loop' => true,
            ],
            'writes' => false,
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function frontendOperationalScorecard(array $checks): array
    {
        $byId = [];
        foreach ($checks as $check) {
            $byId[(string) ($check['id'] ?? '')] = $check;
        }

        $areas = [
            'surface_map' => [
                'label' => 'Surface Map',
                'check_ids' => ['mobile_uses_canon', 'desktop_uses_canon', 'presentation_contract'],
                'scope' => 'desktop/mobile Atlas AI surfaces, rich-input canon and response presentation contract',
            ],
            'interaction_flow' => [
                'label' => 'Interaction Flow',
                'check_ids' => ['ai_interactions_preserves_rich_input', 'forge_accepts_canon', 'routing_anti_regression_tests_present'],
                'scope' => 'composer payload, /ai/interactions, Hyperflow routing, Forge intake and anti-regression paths',
            ],
            'operator_context_audit' => [
                'label' => 'Operator Context/Audit',
                'check_ids' => ['context_trace_audit_consumes_technical_data', 'runtime_ux_operational', 'desktop_control_plane_runtime_governance_ux'],
                'scope' => 'ContextPanel, ResponseAudit, mobile ContextSheet, runtime readiness and control-plane visibility',
            ],
            'frontend_specialist_harness' => [
                'label' => 'Frontend Specialist Harness',
                'check_ids' => ['frontend_operational_understanding'],
                'scope' => 'programming.frontend routing, provider-neutral frontend design harness, required visual/a11y/perf gates and completion rules',
            ],
            'frontend_evidence_rules' => [
                'label' => 'Frontend Evidence Rules',
                'check_ids' => ['frontend_operational_understanding', 'verified_context_execution_loop'],
                'scope' => 'frontend context pack, visual smoke, asset provenance, design review and screenshot-alone-is-insufficient policy',
            ],
            'frontend_product_certification' => [
                'label' => 'Frontend Product Certification',
                'check_ids' => ['frontend_operational_understanding', 'no_attachment_path_still_works'],
                'scope' => 'frontend flow remains usable with and without attachments, with certification evidence and warnings surfaced',
            ],
        ];

        $scoredAreas = [];
        foreach ($areas as $areaId => $area) {
            $checkIds = $area['check_ids'];
            $passed = count(array_filter(
                $checkIds,
                static fn (string $checkId): bool => ($byId[$checkId]['status'] ?? null) === 'passed',
            ));
            $total = count($checkIds);
            $score = $total === 0 ? 0.0 : round(($passed / $total) * 10, 1);

            $scoredAreas[$areaId] = [
                'label' => $area['label'],
                'status' => $passed === $total ? 'ready' : 'blocked',
                'score' => $score,
                'score_basis' => 'certified_check_ratio',
                'passed_checks' => $passed,
                'total_checks' => $total,
                'check_ids' => $checkIds,
                'scope' => $area['scope'],
            ];
        }

        $scores = array_column($scoredAreas, 'score');
        $overall = $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 1);

        return [
            'schema_version' => 'atlas.ai.frontend_operational_scorecard.v1',
            'score_scale' => '0_to_10',
            'status' => min($scores ?: [0.0]) >= 10.0 ? 'ready' : 'partial',
            'overall_score' => $overall,
            'areas' => $scoredAreas,
            'claim_policy' => [
                'local_certification_scope_only' => true,
                'frontend_understanding_claim' => 'operational_contract_and_wiring',
                'visual_screenshot_alone_sufficient' => false,
                'provider_hardcoding_allowed' => false,
                'requires_visual_a11y_perf_or_reason' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function agenticWorkforceScorecard(array $checks): array
    {
        $byId = [];
        foreach ($checks as $check) {
            $byId[(string) ($check['id'] ?? '')] = $check;
        }

        $areas = [
            'governed_agent_runtime' => [
                'label' => 'Governed Agent Runtime',
                'check_ids' => ['agent_control_plane_runtime_standard', 'governed_external_execution_control_plane'],
                'scope' => 'task packets, claim leases, scope locks, dry-run receipts and fail-closed external execution',
            ],
            'operational_roster_workcells' => [
                'label' => 'Operational Roster / Workcells',
                'check_ids' => ['agentic_workcell_runtime'],
                'scope' => 'role roster, topology selection, task graph, context packs, schedule and verification plan',
            ],
            'multi_agent_orchestration' => [
                'label' => 'Multi-Agent Orchestration',
                'check_ids' => ['agent_control_plane_runtime_standard', 'agentic_workcell_runtime'],
                'scope' => 'parallel claim loops, workcell crews, role isolation and independent verification',
            ],
            'outcome_learning' => [
                'label' => 'Outcome Learning',
                'check_ids' => ['agentic_workcell_runtime', 'aemor_runtime', 'assisted_execution_quality'],
                'scope' => 'workcell outcome closure, organization-pattern learning, AEMOR feedback and assisted-execution feedback',
            ],
            'continuous_evolution' => [
                'label' => 'Continuous Evolution',
                'check_ids' => ['autonomous_evolution_loop', 'capability_usage_and_evolution_loop'],
                'scope' => 'AAEL bridge, capability usage evolution and promotion gates',
                'boundary' => 'Continuous evolution remains governed; high-risk or production mutation requires evidence and human signature.',
            ],
            'operator_visibility' => [
                'label' => 'Operator Visibility',
                'check_ids' => ['runtime_ux_operational', 'desktop_control_plane_runtime_governance_ux', 'agentic_workcell_runtime'],
                'scope' => 'control-plane and runtime UX surfaces expose readiness, blockers, workcells and hashes without raw prompts',
            ],
        ];

        $scoredAreas = [];
        foreach ($areas as $areaId => $area) {
            $checkIds = $area['check_ids'];
            $passed = count(array_filter(
                $checkIds,
                static fn (string $checkId): bool => ($byId[$checkId]['status'] ?? null) === 'passed',
            ));
            $total = count($checkIds);
            $score = $total === 0 ? 0.0 : round(($passed / $total) * 10, 1);
            $scoredAreas[$areaId] = [
                'label' => $area['label'],
                'status' => $passed === $total ? 'ready' : 'blocked',
                'score' => $score,
                'score_basis' => 'certified_check_ratio',
                'passed_checks' => $passed,
                'total_checks' => $total,
                'check_ids' => $checkIds,
                'scope' => $area['scope'],
            ];

            if (isset($area['boundary'])) {
                $scoredAreas[$areaId]['boundary'] = $area['boundary'];
            }
        }

        $scores = array_column($scoredAreas, 'score');
        $overall = $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 1);

        return [
            'schema_version' => 'atlas.ai.agentic_workforce_scorecard.v1',
            'score_scale' => '0_to_10',
            'status' => min($scores ?: [0.0]) >= 10.0 ? 'ready' : 'partial',
            'overall_score' => $overall,
            'areas' => $scoredAreas,
            'claim_policy' => [
                'local_certification_scope_only' => true,
                'provider_invoked' => false,
                'agents_spawned_by_certification' => false,
                'external_execution_performed' => false,
                'production_autonomous_mutation_claimed' => false,
                'human_signature_required_for_high_risk' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function assistedExecutionScorecard(array $checks): array
    {
        $byId = [];
        foreach ($checks as $check) {
            $byId[(string) ($check['id'] ?? '')] = $check;
        }

        $areas = [
            'aedpds_delivery_doctrine' => [
                'label' => 'AEDPDS / delivery doctrine',
                'check_ids' => ['execution_doctrine_product_delivery_system', 'assisted_execution_quality'],
                'scope' => 'doctrine selector, gate, Dev/Forge envelope and assisted-execution quality',
            ],
            'aucri_acmf_context_memory' => [
                'label' => 'AUCRI/ACMF / context and cognitive memory',
                'check_ids' => ['context_memory_quality'],
                'scope' => 'context quality certification, AUCRI blocks and cognitive memory fabric',
            ],
            'areg_efficiency_governor' => [
                'label' => 'AREG / runtime efficiency governor',
                'check_ids' => ['runtime_efficiency_governor'],
                'scope' => 'route, budget, context layer admission, outcomes and replay',
            ],
            'aemor_outcome_memory' => [
                'label' => 'AEMOR / execution memory and outcome learning',
                'check_ids' => ['aemor_runtime', 'assisted_execution_quality'],
                'scope' => 'episodes, outcomes, judgment, replay and assisted-execution feedback',
            ],
            'runtime_ux_operational' => [
                'label' => 'Runtime UX / operational visibility',
                'check_ids' => ['runtime_ux_operational'],
                'scope' => 'operator-facing readiness bundle for doctrine, context, AREG and AEMOR',
            ],
            'aael_autonomous_evolution' => [
                'label' => 'AAEL / continuous autonomous evolution',
                'check_ids' => ['autonomous_evolution_loop'],
                'scope' => 'autonomous evolution bridge with AEDPDS, AREG and AEMOR promotion gates',
                'boundary' => 'Production mutation remains gated by sandbox evidence and human signature for high risk.',
            ],
        ];

        $scoredAreas = [];
        foreach ($areas as $areaId => $area) {
            $checkIds = $area['check_ids'];
            $passed = count(array_filter(
                $checkIds,
                static fn (string $checkId): bool => ($byId[$checkId]['status'] ?? null) === 'passed',
            ));
            $total = count($checkIds);
            $score = $total === 0 ? 0.0 : round(($passed / $total) * 10, 1);

            $scoredAreas[$areaId] = [
                'label' => $area['label'],
                'status' => $passed === $total ? 'ready' : 'blocked',
                'score' => $score,
                'score_basis' => 'certified_check_ratio',
                'passed_checks' => $passed,
                'total_checks' => $total,
                'check_ids' => $checkIds,
                'scope' => $area['scope'],
            ];

            if (isset($area['boundary'])) {
                $scoredAreas[$areaId]['boundary'] = $area['boundary'];
            }
        }

        $scores = array_column($scoredAreas, 'score');
        $overall = $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 1);

        return [
            'schema_version' => 'atlas.ai.assisted_execution_scorecard.v1',
            'score_scale' => '0_to_10',
            'status' => min($scores ?: [0.0]) >= 10.0 ? 'ready' : 'partial',
            'overall_score' => $overall,
            'areas' => $scoredAreas,
            'claim_policy' => [
                'local_certification_scope_only' => true,
                'provider_invoked' => false,
                'external_benchmark_run' => false,
                'production_autonomous_mutation_claimed' => false,
                'high_risk_human_signature_required' => true,
            ],
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Evidence refs · canonical artifacts backing the cert */
    /* ---------------------------------------------------------------- */

    /**
     * @return array<int,string>
     */
    private function evidenceRefs(): array
    {
        $refs = [
            'server:'.self::PATH_HYPERFLOW_ENTRY,
            'server:'.self::PATH_AI_INTERACTION_CONTROLLER,
            'server:'.self::PATH_FORGE_INTAKE_SERVICE,
            'server:'.self::PATH_FORGE_WORK_CONTROLLER,
            'server:'.self::PATH_SPECIALIST_FLOWS_READINESS,
            'canon:'.self::PATH_CANON_PACKAGE,
            'mobile:'.self::PATH_MOBILE_CANON_IMPORT,
            'mobile:'.self::PATH_MOBILE_PRESENTATION,
            'mobile:'.self::PATH_MOBILE_CONTEXT_SHEET,
            'desktop:'.self::PATH_DESKTOP_RICH_INPUT_BARREL,
            'desktop:'.self::PATH_DESKTOP_PRESENTATION,
            'desktop:'.self::PATH_DESKTOP_CONTEXT_PANEL,
            'desktop:'.self::PATH_DESKTOP_RESPONSE_AUDIT,
            'desktop:'.self::PATH_DESKTOP_USE_HYPERFLOW,
            'test:'.self::PATH_DESKTOP_ANTIREGRESSION,
            'test:'.self::PATH_DESKTOP_INTEGRATION_TEST,
            'test:'.self::PATH_DESKTOP_RICH_INPUT_TEST,
            'test:'.self::PATH_DESKTOP_PRODUCT_TRIP_TEST,
            'test:'.self::PATH_FORGE_RICH_INPUT_TEST,
            'test:'.self::PATH_FORGE_INTAKE_TEST,
            'server:'.self::PATH_CONTROL_PLANE_SERVICE,
            'desktop:'.self::PATH_DESKTOP_CONTROL_PLANE_SURFACE,
            'desktop:'.self::PATH_DESKTOP_CONTROL_PLANE_CSS,
            'test:'.self::PATH_CONTROL_PLANE_TEST,
            'server:'.self::PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR,
            'server:'.self::PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_CERTIFICATION,
            'server:'.self::PATH_AGENT_CONTROL_PLANE_TASK_PACKET_BUILDER,
            'server:'.self::PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_REPOSITORY,
            'test:'.self::PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR_TEST,
            'test:'.self::PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_TEST,
            'test:'.self::PATH_AGENT_CONTROL_PLANE_TASK_PACKET_TEST,
            'test:'.self::PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_TEST,
            'server:'.self::PATH_AGENTIC_WORKCELL_RUNTIME_SERVICE,
            'server:'.self::PATH_AGENTIC_WORKCELL_CERTIFICATION_SERVICE,
            'server:'.self::PATH_AGENTIC_WORKCELL_COMMAND,
            'server:'.self::PATH_AGENTIC_WORKCELL_CERTIFY_COMMAND,
            'test:'.self::PATH_AGENTIC_WORKCELL_RUNTIME_TEST,
            'test:'.self::PATH_AGENTIC_WORKCELL_CERTIFICATION_TEST,
            'server:'.self::PATH_AGENTIC_WORKCELL_DOC,
            'server:'.self::PATH_ENGINEERING_COMPANY_SERVICE,
            'test:'.self::PATH_ENGINEERING_COMPANY_FEATURE_TEST,
            'test:'.self::PATH_ENGINEERING_COMPANY_UNIT_TEST,
            'server:'.self::PATH_AEMOR_RUNTIME_SERVICE,
            'test:'.self::PATH_AEMOR_RUNTIME_TEST,
            // GOD-DEBULK 3b: IntelligenceFactory evidence refs removed (sources quarantined to archive/).
            'server:'.self::PATH_CODE_INTELLIGENCE_GATE_SERVICE,
            'test:'.self::PATH_CODE_INTELLIGENCE_GATE_TEST,
            'server:'.self::PATH_CODE_INTELLIGENCE_DOC,
            'server:'.self::PATH_PROGRAMMING_ORCHESTRATOR,
            'test:'.self::PATH_PROGRAMMING_ORCHESTRATOR_TEST,
            'server:'.self::PATH_PROGRAMMING_FRONTEND_DOC,
            'server:'.self::PATH_AVCEL_SERVICE,
            'server:'.self::PATH_AVCEL_COMMAND,
            'test:'.self::PATH_AVCEL_TEST,
            'server:'.self::PATH_AVCEL_DOC,
            'server:'.self::PATH_ASSISTED_EXECUTION_SERVICE,
            'test:'.self::PATH_ASSISTED_EXECUTION_TEST,
            'server:'.self::PATH_ASSISTED_EXECUTION_DOC,
            'server:'.self::PATH_PRODUCT_TRUTH_COMPILER_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_RUNTIME_SERVICE,
            'server:'.self::PATH_PRODUCT_FALSIFICATION_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_ENFORCEMENT_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_SERVICE,
            'server:'.self::PATH_PRODUCT_TWIN_SIMULATION_SERVICE,
            'server:'.self::PATH_PRODUCT_TWIN_SIMULATE_COMMAND,
            'server:'.self::PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_COMMAND,
            'server:'.self::PATH_PRODUCT_DELIVERY_CONTROL_PLANE_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_CONTROL_PLANE_COMMAND,
            'server:'.self::PATH_PRODUCT_RELEASE_GATE_SERVICE,
            'server:'.self::PATH_PRODUCT_RELEASE_GATE_COMMAND,
            'server:'.self::PATH_PRODUCT_PROVIDER_MEMORY_SERVICE,
            'server:'.self::PATH_PRODUCT_PROVIDER_MEMORY_COMMAND,
            'server:'.self::PATH_PRODUCT_POLICY_OPTIMIZER_SERVICE,
            'server:'.self::PATH_PRODUCT_POLICY_OPTIMIZER_COMMAND,
            'server:'.self::PATH_PRODUCT_DELIVERY_MULTI_STEP_REPAIR_PLANNER_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_REPAIR_PLAN_COMMAND,
            'server:'.self::PATH_PRODUCT_DELIVERY_EVIDENCE_REPLAY_LAB_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_REPLAY_LAB_COMMAND,
            'server:'.self::PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_COMMAND,
            'server:'.self::PATH_PRODUCT_DELIVERY_REPAIR_BRIDGE_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_MUTATIVE_REPAIR_EXECUTOR_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_PATCH_REQUEST_CONTRACT_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_PATCH_REQUEST_COMMAND,
            'server:'.self::PATH_PRODUCT_DELIVERY_PATCH_PROPOSAL_GATE_SERVICE,
            'server:'.self::PATH_PRODUCT_DELIVERY_REPAIR_EXECUTE_COMMAND,
            'server:'.self::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_MODEL,
            'server:'.self::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_MODEL,
            'server:'.self::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_MIGRATION,
            'server:'.self::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_MIGRATION,
            'test:'.self::PATH_AEDPDS_TEST,
            'server:'.self::PATH_AEDPDS_DOC,
            'server:'.self::PATH_APFPR_DOC,
            'server:'.self::PATH_CONTEXT_QUALITY_SERVICE,
            'server:'.self::PATH_COGNITIVE_MEMORY_FABRIC_SERVICE,
            'server:'.self::PATH_CONTEXT_QUALITY_COMMAND,
            'test:'.self::PATH_CONTEXT_QUALITY_TEST,
            'test:'.self::PATH_COGNITIVE_MEMORY_FABRIC_TEST,
            'server:'.self::PATH_CONTEXT_QUALITY_DOC,
            'server:'.self::PATH_RUNTIME_EFFICIENCY_CERTIFICATION_SERVICE,
            'server:'.self::PATH_RUNTIME_EFFICIENCY_SERVICE,
            'server:'.self::PATH_RUNTIME_EFFICIENCY_COMMAND,
            'test:'.self::PATH_RUNTIME_EFFICIENCY_TEST,
            'test:'.self::PATH_RUNTIME_EFFICIENCY_SERVICE_TEST,
            'server:'.self::PATH_RUNTIME_EFFICIENCY_DOC,
            'server:'.self::PATH_AEMOR_CERTIFICATION_SERVICE,
            'server:'.self::PATH_AEMOR_CERTIFICATION_COMMAND,
            'server:'.self::PATH_AEMOR_DOC,
            'server:'.self::PATH_RUNTIME_UX_CERTIFICATION_SERVICE,
            'server:'.self::PATH_RUNTIME_READINESS_SERVICE,
            'test:'.self::PATH_RUNTIME_UX_TEST,
            'server:'.self::PATH_AAEL_RUNTIME_SERVICE,
            'server:'.self::PATH_AAEL_CERTIFICATION_SERVICE,
            'server:'.self::PATH_AAEL_COMMAND,
            'server:'.self::PATH_AAEL_CERTIFY_COMMAND,
            'test:'.self::PATH_AAEL_TEST,
            'test:'.self::PATH_AAEL_CERTIFICATION_TEST,
            'server:'.self::PATH_AAEL_DOC,
        ];

        return array_values(array_unique($refs));
    }

}
