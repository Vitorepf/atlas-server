<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

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

    public const PATH_MOBILE_CANON_IMPORT = 'atlas-app/lib/api/client.ts';

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

    public const PATH_INTELLIGENCE_FACTORY_RUNTIME_SERVICE = 'app/Services/Ai/IntelligenceFactory/AtlasIntelligenceFactoryRuntimeService.php';

    public const PATH_INTELLIGENCE_FACTORY_CERTIFICATION_SERVICE = 'app/Services/Ai/IntelligenceFactory/AtlasIntelligenceFactoryCertificationService.php';

    public const PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR = 'app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php';

    public const PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_CERTIFICATION = 'app/Services/Ai/SelfConstruction/AgentControlPlaneMultiAgentLoopCertificationService.php';

    public const PATH_AGENT_CONTROL_PLANE_TASK_PACKET_BUILDER = 'app/Services/Ai/SelfConstruction/AgentControlPlaneTaskPacketBuilder.php';

    public const PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_REPOSITORY = 'app/Services/Ai/SelfConstruction/AgentControlPlaneClaimLeaseRepository.php';

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
            $this->frontendOperationalUnderstandingCheck(),  // critical
            $this->assistedExecutionQualityCheck(),          // critical
            $this->executionDoctrineProductDeliverySystemCheck(), // critical
            $this->contextMemoryQualityCheck(),              // critical
            $this->runtimeEfficiencyGovernorCheck(),          // critical
            $this->aemorRuntimeCheck(),                       // critical
            $this->runtimeUxOperationalCheck(),               // critical
            $this->autonomousEvolutionLoopCheck(),            // critical
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

    private function frontendOperationalUnderstandingCheck(): array
    {
        $orchestratorSource = $this->source($this->repoPath(self::PATH_PROGRAMMING_ORCHESTRATOR));
        $orchestratorTestSource = $this->source($this->repoPath(self::PATH_PROGRAMMING_ORCHESTRATOR_TEST));
        $frontendDocSource = $this->source($this->repoPath(self::PATH_PROGRAMMING_FRONTEND_DOC));
        $desktopTripSource = $this->source($this->repoPath(self::PATH_DESKTOP_PRODUCT_TRIP_TEST));
        $desktopPresentationSource = $this->source($this->repoPath(self::PATH_DESKTOP_PRESENTATION));
        $mobilePresentationSource = $this->source($this->repoPath(self::PATH_MOBILE_PRESENTATION));
        $desktopContextPanelSource = $this->source($this->repoPath(self::PATH_DESKTOP_CONTEXT_PANEL));
        $mobileContextSheetSource = $this->source($this->repoPath(self::PATH_MOBILE_CONTEXT_SHEET));

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

        return $this->check('frontend_operational_understanding', $passed, 'critical', [
            'frontend_profile_routed' => $frontendProfileRouted,
            'harness_requires_real_frontend_evidence' => $harnessRequiresRealFrontendEvidence,
            'tests_cover_frontend_contract' => $testsCoverFrontendContract,
            'canonical_frontend_doc_present' => $frontendDocsPresent,
            'desktop_trip_proves_user_flow' => $desktopTripProvesUserFlow,
            'presentation_contract_shared_desktop_mobile' => $presentationContractShared,
            'context_surfaces_consume_runtime_readiness' => $contextSurfacesConsumeRuntime,
            'orchestrator_path' => self::PATH_PROGRAMMING_ORCHESTRATOR,
            'orchestrator_test_path' => self::PATH_PROGRAMMING_ORCHESTRATOR_TEST,
            'frontend_doc_path' => self::PATH_PROGRAMMING_FRONTEND_DOC,
            'desktop_trip_test_path' => self::PATH_DESKTOP_PRODUCT_TRIP_TEST,
        ]);
    }

    private function assistedExecutionQualityCheck(): array
    {
        $serviceSource = $this->source($this->repoPath(self::PATH_ASSISTED_EXECUTION_SERVICE));
        $controllerSource = $this->source($this->repoPath(self::PATH_AI_INTERACTION_CONTROLLER));
        $testSource = $this->source($this->repoPath(self::PATH_ASSISTED_EXECUTION_TEST));
        $docSource = $this->source($this->repoPath(self::PATH_ASSISTED_EXECUTION_DOC));

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
            && $this->callOrderInSource($controllerSource, 'applyAssistedExecutionQuality', '$devRuntime->apply');
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

        return $this->check('assisted_execution_quality', $passed, 'critical', [
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
            'service_path' => self::PATH_ASSISTED_EXECUTION_SERVICE,
            'controller_path' => self::PATH_AI_INTERACTION_CONTROLLER,
            'test_path' => self::PATH_ASSISTED_EXECUTION_TEST,
            'doc_path' => self::PATH_ASSISTED_EXECUTION_DOC,
        ]);
    }

    private function executionDoctrineProductDeliverySystemCheck(): array
    {
        $truthSource = $this->source($this->repoPath(self::PATH_PRODUCT_TRUTH_COMPILER_SERVICE));
        $deliverySource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_RUNTIME_SERVICE));
        $proofSource = $this->source($this->repoPath(self::PATH_PRODUCT_FALSIFICATION_SERVICE));
        $enforcementSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_ENFORCEMENT_SERVICE));
        $outcomeSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_SERVICE));
        $runtimeReceiptSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_SERVICE));
        $productTwinSource = $this->source($this->repoPath(self::PATH_PRODUCT_TWIN_SIMULATION_SERVICE));
        $productTwinCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_TWIN_SIMULATE_COMMAND));
        $riskGovernorSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_SERVICE));
        $riskGovernorCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_COMMAND));
        $productControlPlaneSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_CONTROL_PLANE_SERVICE));
        $productControlPlaneCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_CONTROL_PLANE_COMMAND));
        $releaseGateSource = $this->source($this->repoPath(self::PATH_PRODUCT_RELEASE_GATE_SERVICE));
        $releaseGateCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_RELEASE_GATE_COMMAND));
        $providerMemorySource = $this->source($this->repoPath(self::PATH_PRODUCT_PROVIDER_MEMORY_SERVICE));
        $providerMemoryCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_PROVIDER_MEMORY_COMMAND));
        $policyOptimizerSource = $this->source($this->repoPath(self::PATH_PRODUCT_POLICY_OPTIMIZER_SERVICE));
        $policyOptimizerCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_POLICY_OPTIMIZER_COMMAND));
        $repairPlannerSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_MULTI_STEP_REPAIR_PLANNER_SERVICE));
        $repairPlanCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_REPAIR_PLAN_COMMAND));
        $evidenceReplayLabSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_EVIDENCE_REPLAY_LAB_SERVICE));
        $replayLabCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_REPLAY_LAB_COMMAND));
        $doctrineFitnessSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_SERVICE));
        $doctrineFitnessCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_COMMAND));
        $repairBridgeSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_REPAIR_BRIDGE_SERVICE));
        $mutativeRepairSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_MUTATIVE_REPAIR_EXECUTOR_SERVICE));
        $patchRequestSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_PATCH_REQUEST_CONTRACT_SERVICE));
        $patchRequestCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_PATCH_REQUEST_COMMAND));
        $patchGateSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_PATCH_PROPOSAL_GATE_SERVICE));
        $mutativeRepairCommandSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_REPAIR_EXECUTE_COMMAND));
        $outcomeModelSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_MODEL));
        $runtimeReceiptModelSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_MODEL));
        $outcomeMigrationSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_MIGRATION));
        $runtimeReceiptMigrationSource = $this->source($this->repoPath(self::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_MIGRATION));
        $controllerSource = $this->source($this->repoPath(self::PATH_AI_INTERACTION_CONTROLLER));
        $aedpdsDocSource = $this->source($this->repoPath(self::PATH_AEDPDS_DOC));
        $apfprDocSource = $this->source($this->repoPath(self::PATH_APFPR_DOC));
        $testSource = $this->source($this->repoPath(self::PATH_AEDPDS_TEST));
        $interactionTestSource = $this->source($this->repoPath('tests/Feature/Ai/AtlasDevRuntimeInteractionApiTest.php'));

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
            && $this->callOrderInSource($controllerSource, 'applyProductDeliveryRuntime', 'applyAssistedExecutionQuality');
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

        return $this->check('execution_doctrine_product_delivery_system', $passed, 'critical', [
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
            'truth_service_path' => self::PATH_PRODUCT_TRUTH_COMPILER_SERVICE,
            'delivery_service_path' => self::PATH_PRODUCT_DELIVERY_RUNTIME_SERVICE,
            'proof_service_path' => self::PATH_PRODUCT_FALSIFICATION_SERVICE,
            'enforcement_service_path' => self::PATH_PRODUCT_DELIVERY_ENFORCEMENT_SERVICE,
            'outcome_memory_service_path' => self::PATH_PRODUCT_DELIVERY_OUTCOME_MEMORY_SERVICE,
            'runtime_receipt_service_path' => self::PATH_PRODUCT_DELIVERY_RUNTIME_RECEIPT_SERVICE,
            'product_twin_service_path' => self::PATH_PRODUCT_TWIN_SIMULATION_SERVICE,
            'product_twin_command_path' => self::PATH_PRODUCT_TWIN_SIMULATE_COMMAND,
            'risk_governor_service_path' => self::PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_SERVICE,
            'risk_governor_command_path' => self::PATH_PRODUCT_DELIVERY_RISK_GOVERNOR_COMMAND,
            'product_control_plane_service_path' => self::PATH_PRODUCT_DELIVERY_CONTROL_PLANE_SERVICE,
            'product_control_plane_command_path' => self::PATH_PRODUCT_DELIVERY_CONTROL_PLANE_COMMAND,
            'product_release_gate_service_path' => self::PATH_PRODUCT_RELEASE_GATE_SERVICE,
            'product_release_gate_command_path' => self::PATH_PRODUCT_RELEASE_GATE_COMMAND,
            'provider_memory_service_path' => self::PATH_PRODUCT_PROVIDER_MEMORY_SERVICE,
            'provider_memory_command_path' => self::PATH_PRODUCT_PROVIDER_MEMORY_COMMAND,
            'product_policy_optimizer_service_path' => self::PATH_PRODUCT_POLICY_OPTIMIZER_SERVICE,
            'product_policy_optimizer_command_path' => self::PATH_PRODUCT_POLICY_OPTIMIZER_COMMAND,
            'multi_step_repair_planner_service_path' => self::PATH_PRODUCT_DELIVERY_MULTI_STEP_REPAIR_PLANNER_SERVICE,
            'repair_plan_command_path' => self::PATH_PRODUCT_DELIVERY_REPAIR_PLAN_COMMAND,
            'evidence_replay_lab_service_path' => self::PATH_PRODUCT_DELIVERY_EVIDENCE_REPLAY_LAB_SERVICE,
            'replay_lab_command_path' => self::PATH_PRODUCT_DELIVERY_REPLAY_LAB_COMMAND,
            'doctrine_fitness_service_path' => self::PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_SERVICE,
            'doctrine_fitness_command_path' => self::PATH_PRODUCT_DELIVERY_DOCTRINE_FITNESS_COMMAND,
            'repair_bridge_service_path' => self::PATH_PRODUCT_DELIVERY_REPAIR_BRIDGE_SERVICE,
            'mutative_repair_executor_service_path' => self::PATH_PRODUCT_DELIVERY_MUTATIVE_REPAIR_EXECUTOR_SERVICE,
            'patch_request_contract_service_path' => self::PATH_PRODUCT_DELIVERY_PATCH_REQUEST_CONTRACT_SERVICE,
            'patch_proposal_gate_service_path' => self::PATH_PRODUCT_DELIVERY_PATCH_PROPOSAL_GATE_SERVICE,
            'controller_path' => self::PATH_AI_INTERACTION_CONTROLLER,
            'test_path' => self::PATH_AEDPDS_TEST,
            'doc_path' => self::PATH_AEDPDS_DOC,
        ]);
    }

    private function contextMemoryQualityCheck(): array
    {
        $certSource = $this->source($this->repoPath(self::PATH_CONTEXT_QUALITY_SERVICE));
        $memorySource = $this->source($this->repoPath(self::PATH_COGNITIVE_MEMORY_FABRIC_SERVICE));
        $commandSource = $this->source($this->repoPath(self::PATH_CONTEXT_QUALITY_COMMAND));
        $certTestSource = $this->source($this->repoPath(self::PATH_CONTEXT_QUALITY_TEST));
        $memoryTestSource = $this->source($this->repoPath(self::PATH_COGNITIVE_MEMORY_FABRIC_TEST));
        $docSource = $this->source($this->repoPath(self::PATH_CONTEXT_QUALITY_DOC));

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

        return $this->check('context_memory_quality', $passed, 'critical', [
            'context_quality_certifies_aucri_blocks' => $certifiesAucriBlocks,
            'cognitive_memory_fabric_present' => $cognitiveMemoryPresent,
            'command_present' => $commandPresent,
            'tests_cover_quality_and_memory' => $testsCover,
            'canonical_doc_present' => $docPresent,
            'certification_service_path' => self::PATH_CONTEXT_QUALITY_SERVICE,
            'memory_fabric_path' => self::PATH_COGNITIVE_MEMORY_FABRIC_SERVICE,
            'test_path' => self::PATH_CONTEXT_QUALITY_TEST,
        ]);
    }

    private function runtimeEfficiencyGovernorCheck(): array
    {
        $certSource = $this->source($this->repoPath(self::PATH_RUNTIME_EFFICIENCY_CERTIFICATION_SERVICE));
        $runtimeSource = $this->source($this->repoPath(self::PATH_RUNTIME_EFFICIENCY_SERVICE));
        $commandSource = $this->source($this->repoPath(self::PATH_RUNTIME_EFFICIENCY_COMMAND));
        $testSource = $this->source($this->repoPath(self::PATH_RUNTIME_EFFICIENCY_TEST));
        $serviceTestSource = $this->source($this->repoPath(self::PATH_RUNTIME_EFFICIENCY_SERVICE_TEST));
        $docSource = $this->source($this->repoPath(self::PATH_RUNTIME_EFFICIENCY_DOC));

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

        return $this->check('runtime_efficiency_governor', $passed, 'critical', [
            'certifies_core_paths' => $certifiesCorePaths,
            'runtime_governs_context_layers_and_outcome' => $runtimeGoverns,
            'command_present' => $commandPresent,
            'tests_cover_core_paths' => $testsCover,
            'canonical_doc_present' => $docPresent,
            'certification_service_path' => self::PATH_RUNTIME_EFFICIENCY_CERTIFICATION_SERVICE,
            'runtime_service_path' => self::PATH_RUNTIME_EFFICIENCY_SERVICE,
            'test_path' => self::PATH_RUNTIME_EFFICIENCY_TEST,
            'service_test_path' => self::PATH_RUNTIME_EFFICIENCY_SERVICE_TEST,
        ]);
    }

    private function aemorRuntimeCheck(): array
    {
        $certSource = $this->source($this->repoPath(self::PATH_AEMOR_CERTIFICATION_SERVICE));
        $runtimeSource = $this->source($this->repoPath(self::PATH_AEMOR_RUNTIME_SERVICE));
        $commandSource = $this->source($this->repoPath(self::PATH_AEMOR_CERTIFICATION_COMMAND));
        $testSource = $this->source($this->repoPath(self::PATH_AEMOR_RUNTIME_TEST));
        $docSource = $this->source($this->repoPath(self::PATH_AEMOR_DOC));

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

        return $this->check('aemor_runtime', $passed, 'critical', [
            'certifies_judgment_and_intelligence_outputs' => $certifiesJudgment,
            'runtime_closes_outcome_and_replays' => $runtimeClosesOutcome,
            'command_present' => $commandPresent,
            'tests_cover_outcome_risk_replay' => $testsCover,
            'canonical_doc_present' => $docPresent,
            'certification_service_path' => self::PATH_AEMOR_CERTIFICATION_SERVICE,
            'runtime_service_path' => self::PATH_AEMOR_RUNTIME_SERVICE,
            'test_path' => self::PATH_AEMOR_RUNTIME_TEST,
        ]);
    }

    private function runtimeUxOperationalCheck(): array
    {
        $certSource = $this->source($this->repoPath(self::PATH_RUNTIME_UX_CERTIFICATION_SERVICE));
        $readinessSource = $this->source($this->repoPath(self::PATH_RUNTIME_READINESS_SERVICE));
        $testSource = $this->source($this->repoPath(self::PATH_RUNTIME_UX_TEST));
        $mobileContextSource = $this->source($this->repoPath(self::PATH_MOBILE_CONTEXT_SHEET));

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

        return $this->check('runtime_ux_operational', $passed, 'critical', [
            'certification_checks_assisted_execution_ux' => $certifiesAssistedUx,
            'readiness_emits_assisted_execution_bundle' => $readinessEmitsBundle,
            'tests_cover_assisted_execution_bundle' => $testsCover,
            'mobile_context_renders_operational_signals' => $mobileRenders,
            'certification_service_path' => self::PATH_RUNTIME_UX_CERTIFICATION_SERVICE,
            'readiness_service_path' => self::PATH_RUNTIME_READINESS_SERVICE,
            'test_path' => self::PATH_RUNTIME_UX_TEST,
        ]);
    }

    private function autonomousEvolutionLoopCheck(): array
    {
        $runtimeSource = $this->source($this->repoPath(self::PATH_AAEL_RUNTIME_SERVICE));
        $certSource = $this->source($this->repoPath(self::PATH_AAEL_CERTIFICATION_SERVICE));
        $commandSource = $this->source($this->repoPath(self::PATH_AAEL_COMMAND));
        $certCommandSource = $this->source($this->repoPath(self::PATH_AAEL_CERTIFY_COMMAND));
        $testSource = $this->source($this->repoPath(self::PATH_AAEL_TEST));
        $certTestSource = $this->source($this->repoPath(self::PATH_AAEL_CERTIFICATION_TEST));
        $docSource = $this->source($this->repoPath(self::PATH_AAEL_DOC));

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

        return $this->check('autonomous_evolution_loop', $passed, 'critical', [
            'runtime_has_assisted_execution_bridge' => $runtimeHasBridge,
            'certification_checks_bridge' => $certifiesBridge,
            'commands_present' => $commandsPresent,
            'tests_cover_bridge_and_certification' => $testsCover,
            'canonical_doc_present' => $docPresent,
            'runtime_service_path' => self::PATH_AAEL_RUNTIME_SERVICE,
            'certification_service_path' => self::PATH_AAEL_CERTIFICATION_SERVICE,
            'test_path' => self::PATH_AAEL_TEST,
            'doc_path' => self::PATH_AAEL_DOC,
        ]);
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
            'server:'.self::PATH_INTELLIGENCE_FACTORY_RUNTIME_SERVICE,
            'server:'.self::PATH_INTELLIGENCE_FACTORY_CERTIFICATION_SERVICE,
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

    /* ---------------------------------------------------------------- */
    /* Helpers */
    /* ---------------------------------------------------------------- */

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, string $severity, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'severity' => $severity,
            'evidence' => $evidence,
        ];
    }

    private function source(string $path): string
    {
        return is_file($path) ? File::get($path) : '';
    }

    private function callOrderInSource(string $source, string $needle, string $after): bool
    {
        $needleAt = strpos($source, $needle);
        $afterAt = strpos($source, $after);
        if ($needleAt === false || $afterAt === false) {
            return false;
        }

        return $needleAt < $afterAt;
    }

    private function importsRichInputCanon(string $source): bool
    {
        return str_contains($source, '@atlas/rich-input-canon')
            || str_contains($source, 'atlas-rich-input-canon')
            || str_contains($source, 'rich-input-canon');
    }

    private function repoPath(string $relative): string
    {
        if (str_starts_with($relative, 'app/')
            || str_starts_with($relative, 'database/')
            || str_starts_with($relative, 'tests/')
            || str_starts_with($relative, 'docs/')
        ) {
            return rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relative, '/');
        }

        return rtrim(dirname(base_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relative, '/');
    }
}
