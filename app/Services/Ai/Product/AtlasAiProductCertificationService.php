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

    public const PATH_AVCEL_SERVICE = 'app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php';

    public const PATH_AVCEL_COMMAND = 'app/Console/Commands/AtlasVerifiedContextExecutionLoopCommand.php';

    public const PATH_AVCEL_TEST = 'tests/Feature/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopServiceTest.php';

    public const PATH_AVCEL_DOC = 'docs/engineering-knowledge-base/atlas-verified-context-execution-loop.md';

    public const PATH_CODE_INTELLIGENCE_GATE_SERVICE = 'app/Services/Engineering/AtlasCodeIntelligenceAutomaticGateService.php';

    public const PATH_CODE_INTELLIGENCE_GATE_TEST = 'tests/Feature/Engineering/AtlasCodeIntelligenceAutomaticGateServiceTest.php';

    public const PATH_CODE_INTELLIGENCE_DOC = 'docs/engineering-knowledge-base/code-intelligence.md';

    public const PATH_PROGRAMMING_CODE_INTELLIGENCE_GATE = 'app/Services/Ai/Programming/Governance/Gates/ProgrammingCodeIntelligenceGate.php';

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

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->hyperflowV2EntryCheck(),                // critical
            $this->aiInteractionsPreservesRichInputCheck(), // critical
            $this->universalComposerCanonCheck(),          // critical
            $this->mobileUsesCanonCheck(),                 // critical
            $this->desktopUsesCanonCheck(),                // critical
            $this->forgeAcceptsCanonCheck(),               // critical
            $this->presentationContractCheck(),            // critical
            $this->contextTraceAuditCheck(),               // critical
            $this->specialistFlowsRegisteredCheck(),       // critical
            $this->routingAntiRegressionCheck(),           // critical
            $this->forgeHashAndContextRefsCheck(),         // critical
            $this->noAttachmentPathStillWorksCheck(),      // warn (not blocker)
            $this->desktopControlPlaneRuntimeUxCheck(),     // critical
            $this->agentControlPlaneRuntimeStandardCheck(), // critical
            $this->governedExternalExecutionCheck(),        // critical
            $this->autonomousCompanyRuntimeCheck(),         // critical
            $this->capabilityEvolutionLoopCheck(),          // critical
            $this->codeIntelligenceAutomaticGateCheck(),     // critical
            $this->verifiedContextExecutionLoopCheck(),      // critical
            $this->assistedExecutionQualityCheck(),          // critical
            $this->executionDoctrineProductDeliverySystemCheck(), // critical
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
                'covers_external_execution_governance' => true,
                'covers_internal_autonomous_company_runtime' => true,
                'covers_capability_usage_evolution' => true,
                'covers_code_intelligence_automatic_gate' => true,
                'covers_verified_context_execution_loop' => true,
                'covers_assisted_execution_quality' => true,
                'covers_execution_doctrine_product_delivery_system' => true,
            ],
            'writes' => false,
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /* ---------------------------------------------------------------- */
    /* Check 1 · Hyperflow V2 entry present + 5-stage pipeline */
    /* ---------------------------------------------------------------- */

    private function hyperflowV2EntryCheck(): array
    {
        $source = $this->source($this->repoPath(self::PATH_HYPERFLOW_ENTRY));
        $pipelineStages = [
            'IntentKernelService' => str_contains($source, 'IntentKernelService'),
            'DomainRouterService' => str_contains($source, 'DomainRouterService'),
            'FlowRouterService' => str_contains($source, 'FlowRouterService'),
            'RuntimeDispatchService' => str_contains($source, 'RuntimeDispatchService'),
            'DecisionReceiptService' => str_contains($source, 'DecisionReceiptService'),
        ];
        $envelopeCanon = str_contains($source, "'atlas.ai.hyperflow_runtime.v1'");
        $entryClass = str_contains($source, 'class AtlasHyperflowEntryService');

        $passed = $entryClass && $envelopeCanon && ! in_array(false, $pipelineStages, true);

        return $this->check('hyperflow_v2_entry', $passed, 'critical', [
            'entry_class_present' => $entryClass,
            'envelope_schema_version_present' => $envelopeCanon,
            'pipeline_stages' => $pipelineStages,
            'evidence_path' => self::PATH_HYPERFLOW_ENTRY,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 2 · /ai/interactions preserves rich_input_payload before */
    /*           the Hyperflow entry runs */
    /* ---------------------------------------------------------------- */

    private function aiInteractionsPreservesRichInputCheck(): array
    {
        $source = $this->source($this->repoPath(self::PATH_AI_INTERACTION_CONTROLLER));
        $extractsRichInput = str_contains($source, "\$data['rich_input_payload'] ?? null");
        $mergesIntoPayload = str_contains($source, 'payloadWithRichInputPayload');
        $passesToHyperflow = str_contains($source, '$hyperflowEntry->run($data)');
        $mergeBeforeRun = $this->callOrderInSource($source, 'payloadWithRichInputPayload', '$hyperflowEntry->run');

        $requestSource = $this->source($this->repoPath(self::PATH_STORE_AI_INTERACTION_REQUEST));
        $validatesSchema = str_contains($requestSource, 'rich_input_payload.schema_version');
        $validatesShape = str_contains($requestSource, 'rich_input_payload.uploaded_image_ids')
            && str_contains($requestSource, 'rich_input_payload.uploaded_document_ids')
            && str_contains($requestSource, 'rich_input_payload.source_manifest');

        $passed = $extractsRichInput && $mergesIntoPayload && $passesToHyperflow
            && $mergeBeforeRun && $validatesSchema && $validatesShape;

        return $this->check('ai_interactions_preserves_rich_input', $passed, 'critical', [
            'controller_extracts_rich_input' => $extractsRichInput,
            'controller_merges_into_payload' => $mergesIntoPayload,
            'controller_passes_to_hyperflow' => $passesToHyperflow,
            'merge_happens_before_hyperflow_run' => $mergeBeforeRun,
            'request_validates_schema_version' => $validatesSchema,
            'request_validates_canonical_shape' => $validatesShape,
            'controller_path' => self::PATH_AI_INTERACTION_CONTROLLER,
            'request_path' => self::PATH_STORE_AI_INTERACTION_REQUEST,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 3 · Universal Composer canon package present + exports */
    /* ---------------------------------------------------------------- */

    private function universalComposerCanonCheck(): array
    {
        $indexPath = $this->repoPath(self::PATH_CANON_PACKAGE);
        $typesPath = $this->repoPath(self::PATH_CANON_TYPES);
        $packagePresent = is_file($indexPath) && is_file($typesPath);
        $typesSource = $this->source($typesPath);
        $schemaConstant = str_contains($typesSource, "'atlas.rich_input.payload.v1'")
            && str_contains($typesSource, 'ATLAS_RICH_INPUT_PAYLOAD_SCHEMA');
        $payloadType = str_contains($typesSource, 'AtlasRichInputPayload')
            && str_contains($typesSource, 'source_manifest')
            && str_contains($typesSource, 'url_attachments')
            && str_contains($typesSource, 'text_blocks');

        $indexSource = $this->source($indexPath);
        $exportsBuilders = str_contains($indexSource, 'buildRichInputPayload')
            && str_contains($indexSource, 'buildSourceManifest')
            && str_contains($indexSource, 'estimateRichInputTokens');

        $passed = $packagePresent && $schemaConstant && $payloadType && $exportsBuilders;

        return $this->check('universal_composer_canon_present', $passed, 'critical', [
            'package_files_present' => $packagePresent,
            'schema_constant_declared' => $schemaConstant,
            'payload_type_complete' => $payloadType,
            'builders_exported' => $exportsBuilders,
            'package_path' => self::PATH_CANON_PACKAGE,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 4 · Mobile (atlas-app) uses canon */
    /* ---------------------------------------------------------------- */

    private function mobileUsesCanonCheck(): array
    {
        // Mobile re-exports the canon through `lib/richInput/*` (barrel pattern
        // identical to the Desktop). The api client then imports the local
        // barrel — this is the canon usage we certify.
        $barrelSourceManifest = $this->source($this->repoPath(self::PATH_MOBILE_RICH_INPUT_BARREL));
        $barrelTypes = $this->source($this->repoPath(self::PATH_MOBILE_RICH_INPUT_TYPES));
        $barrelReExportsCanon = ($this->importsRichInputCanon($barrelSourceManifest)
            && $this->importsRichInputCanon($barrelTypes));

        $clientSource = $this->source($this->repoPath(self::PATH_MOBILE_CANON_IMPORT));
        $usesBuilder = str_contains($clientSource, 'buildRichInputPayload');
        $sendsToInteractions = str_contains($clientSource, "'/ai/interactions'")
            && str_contains($clientSource, 'rich_input_payload');

        $passed = $barrelReExportsCanon && $usesBuilder && $sendsToInteractions;

        return $this->check('mobile_uses_canon', $passed, 'critical', [
            'mobile_barrel_reexports_canon_package' => $barrelReExportsCanon,
            'mobile_client_uses_canon_builder' => $usesBuilder,
            'mobile_client_sends_rich_input_payload_to_interactions' => $sendsToInteractions,
            'mobile_barrel_path' => self::PATH_MOBILE_RICH_INPUT_BARREL,
            'mobile_types_path' => self::PATH_MOBILE_RICH_INPUT_TYPES,
            'mobile_client_path' => self::PATH_MOBILE_CANON_IMPORT,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 5 · Desktop uses canon (barrel re-exports the package) */
    /* ---------------------------------------------------------------- */

    private function desktopUsesCanonCheck(): array
    {
        $barrelPath = $this->repoPath(self::PATH_DESKTOP_RICH_INPUT_BARREL);
        $typesPath = $this->repoPath(self::PATH_DESKTOP_RICH_INPUT_TYPES);
        $barrelPresent = is_file($barrelPath);
        $typesPresent = is_file($typesPath);
        $typesSource = $this->source($typesPath);
        $reExportsCanon = str_contains($typesSource, "from '@atlas/rich-input-canon'");

        $useHyperflowSource = $this->source($this->repoPath(self::PATH_DESKTOP_USE_HYPERFLOW));
        $useHyperflowPresent = is_file($this->repoPath(self::PATH_DESKTOP_USE_HYPERFLOW))
            && str_contains($useHyperflowSource, 'export function useHyperflowRuntime')
            && str_contains($useHyperflowSource, 'export function buildHyperflowRuntimeView');

        $passed = $barrelPresent && $typesPresent && $reExportsCanon && $useHyperflowPresent;

        return $this->check('desktop_uses_canon', $passed, 'critical', [
            'desktop_rich_input_barrel_present' => $barrelPresent,
            'desktop_types_present' => $typesPresent,
            'desktop_types_reexport_canon_package' => $reExportsCanon,
            'desktop_hyperflow_view_model_hook_present' => $useHyperflowPresent,
            'barrel_path' => self::PATH_DESKTOP_RICH_INPUT_BARREL,
            'hook_path' => self::PATH_DESKTOP_USE_HYPERFLOW,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 6 · Forge accepts payload canon */
    /* ---------------------------------------------------------------- */

    private function forgeAcceptsCanonCheck(): array
    {
        $intakeSource = $this->source($this->repoPath(self::PATH_FORGE_INTAKE_SERVICE));
        $hasNormalizer = str_contains($intakeSource, 'private function normalizeRichInputPayload');
        $defaultsCanonSchema = str_contains($intakeSource, "'atlas.rich_input.payload.v1'");
        $persistsSchemaVersion = str_contains($intakeSource, 'rich_input_schema_version');

        $workControllerSource = $this->source($this->repoPath(self::PATH_FORGE_WORK_CONTROLLER));
        $controllerNormalises = str_contains($workControllerSource, 'normaliseRichInput')
            || str_contains($workControllerSource, 'normalizeRichInputPayload')
            || str_contains($workControllerSource, 'rich_input');
        $intakeReadyNotAutoFromAttachment = str_contains($workControllerSource, "'forge_work_intake_ready'")
            && (str_contains($workControllerSource, "'forge_work_intake_ready' => false")
                || str_contains($workControllerSource, '\'forge_work_intake_ready\' => false'));

        $passed = $hasNormalizer && $defaultsCanonSchema && $persistsSchemaVersion
            && $controllerNormalises && $intakeReadyNotAutoFromAttachment;

        return $this->check('forge_accepts_canon', $passed, 'critical', [
            'intake_service_has_normalizer' => $hasNormalizer,
            'intake_service_defaults_canon_schema' => $defaultsCanonSchema,
            'intake_service_persists_schema_version' => $persistsSchemaVersion,
            'work_controller_normalises_rich_input' => $controllerNormalises,
            'forge_work_intake_ready_is_not_set_by_attachment_alone' => $intakeReadyNotAutoFromAttachment,
            'intake_service_path' => self::PATH_FORGE_INTAKE_SERVICE,
            'controller_path' => self::PATH_FORGE_WORK_CONTROLLER,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 7 · Presentation Contract present on both surfaces and */
    /*           wired into the message body renderer */
    /* ---------------------------------------------------------------- */

    private function presentationContractCheck(): array
    {
        $mobilePath = $this->repoPath(self::PATH_MOBILE_PRESENTATION);
        $desktopPath = $this->repoPath(self::PATH_DESKTOP_PRESENTATION);
        $mobilePresent = is_file($mobilePath);
        $desktopPresent = is_file($desktopPath);

        $mobileSource = $this->source($mobilePath);
        $desktopSource = $this->source($desktopPath);
        $mobileExportsFn = str_contains($mobileSource, 'export function projectPresentation');
        $desktopExportsFn = str_contains($desktopSource, 'export function projectPresentation');
        $mobileSeparatesTechnical = str_contains($mobileSource, 'ALWAYS_METADATA_SECTIONS')
            || str_contains($mobileSource, 'source_refs');
        $desktopSeparatesTechnical = str_contains($desktopSource, 'ALWAYS_METADATA_SECTIONS')
            && str_contains($desktopSource, 'source_refs')
            && str_contains($desktopSource, 'uncertainty');

        $mobileTurnSource = $this->source($this->repoPath(self::PATH_MOBILE_TURN_MODEL));
        $mobileWired = str_contains($mobileTurnSource, 'projectPresentation');

        $desktopConversationSource = $this->source($this->repoPath(self::PATH_DESKTOP_CONVERSATION));
        $desktopWired = str_contains($desktopConversationSource, 'projectPresentation');

        $passed = $mobilePresent && $desktopPresent
            && $mobileExportsFn && $desktopExportsFn
            && $mobileSeparatesTechnical && $desktopSeparatesTechnical
            && $mobileWired && $desktopWired;

        return $this->check('presentation_contract', $passed, 'critical', [
            'mobile_module_present' => $mobilePresent,
            'desktop_module_present' => $desktopPresent,
            'mobile_exports_project_presentation' => $mobileExportsFn,
            'desktop_exports_project_presentation' => $desktopExportsFn,
            'mobile_separates_technical_sections' => $mobileSeparatesTechnical,
            'desktop_separates_technical_sections' => $desktopSeparatesTechnical,
            'mobile_wired_in_turn_model' => $mobileWired,
            'desktop_wired_in_conversation' => $desktopWired,
            'mobile_path' => self::PATH_MOBILE_PRESENTATION,
            'desktop_path' => self::PATH_DESKTOP_PRESENTATION,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 8 · Context/Trace/Audit consumes technical data */
    /* ---------------------------------------------------------------- */

    private function contextTraceAuditCheck(): array
    {
        $desktopContextSource = $this->source($this->repoPath(self::PATH_DESKTOP_CONTEXT_PANEL));
        $desktopAuditSource = $this->source($this->repoPath(self::PATH_DESKTOP_RESPONSE_AUDIT));

        $desktopContextReadsViewModel = str_contains($desktopContextSource, 'useHyperflowRuntime')
            && str_contains($desktopContextSource, 'hyperflow.flowId')
            && str_contains($desktopContextSource, 'hyperflow.intent');
        $desktopAuditConsumesSections = str_contains($desktopAuditSource, 'sections: Record<string, string[]>')
            && str_contains($desktopAuditSource, 'source_refs');

        $mobileContextSource = $this->source($this->repoPath(self::PATH_MOBILE_CONTEXT_SHEET));
        $mobileContextConsumes = is_file($this->repoPath(self::PATH_MOBILE_CONTEXT_SHEET))
            && (str_contains($mobileContextSource, 'AtlasAiContextSheet')
                || str_contains($mobileContextSource, 'context'));

        $passed = $desktopContextReadsViewModel && $desktopAuditConsumesSections && $mobileContextConsumes;

        return $this->check('context_trace_audit_consumes_technical_data', $passed, 'critical', [
            'desktop_context_panel_reads_hyperflow_view_model' => $desktopContextReadsViewModel,
            'desktop_response_audit_consumes_sections' => $desktopAuditConsumesSections,
            'mobile_context_sheet_present' => $mobileContextConsumes,
            'desktop_context_panel_path' => self::PATH_DESKTOP_CONTEXT_PANEL,
            'desktop_audit_path' => self::PATH_DESKTOP_RESPONSE_AUDIT,
            'mobile_context_sheet_path' => self::PATH_MOBILE_CONTEXT_SHEET,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 9 · Specialist flows registered + canon flow ids exist */
    /* ---------------------------------------------------------------- */

    private function specialistFlowsRegisteredCheck(): array
    {
        $flowRouterPresent = is_file($this->repoPath(self::PATH_FLOW_ROUTER));
        $readinessPath = $this->repoPath(self::PATH_SPECIALIST_FLOWS_READINESS);
        $readinessPresent = is_file($readinessPath);
        $readinessSource = $this->source($readinessPath);
        $registersFlows = str_contains($readinessSource, 'AtlasAiSpecialistFlowExecutionService')
            || str_contains($readinessSource, 'AtlasAiSpecialistFlowRuntimeService');

        $canonSource = $this->source($this->repoPath(self::PATH_ROUTER_CANON));
        $declaresFlows = str_contains($canonSource, 'specialist flow_ids')
            || str_contains($canonSource, 'SpecialistFlows registry');

        $passed = $flowRouterPresent && $readinessPresent && $registersFlows && $declaresFlows;

        return $this->check('specialist_flows_registered', $passed, 'critical', [
            'flow_router_present' => $flowRouterPresent,
            'specialist_flows_readiness_service_present' => $readinessPresent,
            'readiness_references_specialist_runtime' => $registersFlows,
            'router_canon_declares_specialist_flow_ids' => $declaresFlows,
            'flow_router_path' => self::PATH_FLOW_ROUTER,
            'readiness_path' => self::PATH_SPECIALIST_FLOWS_READINESS,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 10 · Anti-regression routing tests guard the canonical */
    /*            routing decisions Atlas AI relies on */
    /* ---------------------------------------------------------------- */

    private function routingAntiRegressionCheck(): array
    {
        $desktopAntiRegPath = $this->repoPath(self::PATH_DESKTOP_ANTIREGRESSION);
        $integrationPath = $this->repoPath(self::PATH_DESKTOP_INTEGRATION_TEST);
        $antiRegPresent = is_file($desktopAntiRegPath);
        $integrationPresent = is_file($integrationPath);

        $antiRegSource = $this->source($desktopAntiRegPath);
        $coversResearch = str_contains($antiRegSource, 'test_research_prompt_from_desktop_routes_to_atlas_research');
        $coversFinance = str_contains($antiRegSource, 'finance')
            && str_contains($antiRegSource, 'does_not_force_atlas_dev');
        $coversProgramming = str_contains($antiRegSource, 'atlas_dev')
            || str_contains($antiRegSource, 'FLOW_DEV');
        $coversObra = str_contains($antiRegSource, 'atlas_forge')
            || str_contains($antiRegSource, 'FLOW_FORGE')
            || str_contains($antiRegSource, 'forge_promotion');

        $passed = $antiRegPresent && $integrationPresent
            && $coversResearch && $coversFinance && $coversProgramming && $coversObra;

        return $this->check('routing_anti_regression_tests_present', $passed, 'critical', [
            'desktop_anti_regression_test_present' => $antiRegPresent,
            'hyperflow_entry_integration_test_present' => $integrationPresent,
            'covers_research_not_programming_dev' => $coversResearch,
            'covers_finance_not_atlas_dev' => $coversFinance,
            'covers_programming_routes_to_atlas_dev' => $coversProgramming,
            'covers_obra_handoff_to_forge' => $coversObra,
            'desktop_anti_regression_path' => self::PATH_DESKTOP_ANTIREGRESSION,
            'integration_test_path' => self::PATH_DESKTOP_INTEGRATION_TEST,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 11 · Forge strips raw text → content_hash and derives */
    /*            context_refs from source_manifest */
    /* ---------------------------------------------------------------- */

    private function forgeHashAndContextRefsCheck(): array
    {
        $intakeSource = $this->source($this->repoPath(self::PATH_FORGE_INTAKE_SERVICE));
        $hashesUrl = str_contains($intakeSource, "hash('sha256', \$attachment['url'])");
        $hashesContent = str_contains($intakeSource, "hash('sha256', \$block['content'])");
        $derivesContextRefs = str_contains($intakeSource, 'url_attachment:')
            && str_contains($intakeSource, 'text_block:')
            && str_contains($intakeSource, 'content_hash');
        $forgeTestPresent = is_file($this->repoPath(self::PATH_FORGE_RICH_INPUT_TEST))
            && is_file($this->repoPath(self::PATH_FORGE_INTAKE_TEST));

        $passed = $hashesUrl && $hashesContent && $derivesContextRefs && $forgeTestPresent;

        return $this->check('forge_strips_raw_text_to_hash_and_derives_context_refs', $passed, 'critical', [
            'intake_hashes_url_attachments' => $hashesUrl,
            'intake_hashes_text_block_content' => $hashesContent,
            'intake_derives_context_refs_from_manifest' => $derivesContextRefs,
            'forge_rich_input_tests_present' => $forgeTestPresent,
            'intake_service_path' => self::PATH_FORGE_INTAKE_SERVICE,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 12 · No-attachment flow continues to work (warn, not block) */
    /* ---------------------------------------------------------------- */

    private function noAttachmentPathStillWorksCheck(): array
    {
        $tripPath = $this->repoPath(self::PATH_DESKTOP_PRODUCT_TRIP_TEST);
        $richInputPath = $this->repoPath(self::PATH_DESKTOP_RICH_INPUT_TEST);
        $tripPresent = is_file($tripPath);
        $richInputPresent = is_file($richInputPath);
        $tripSource = $this->source($tripPath);
        $richInputSource = $this->source($richInputPath);
        $tripCoversNoAttachment = str_contains($tripSource, 'fluxo sem anexo')
            || str_contains($tripSource, 'sem rich_input_payload');
        $richInputCoversNoAttachment = str_contains($richInputSource, 'SEM anexos')
            || str_contains($richInputSource, 'sem anexos');

        $passed = $tripPresent && $richInputPresent
            && ($tripCoversNoAttachment || $richInputCoversNoAttachment);

        return $this->check('no_attachment_path_still_works', $passed, 'warn', [
            'desktop_product_trip_test_present' => $tripPresent,
            'desktop_rich_input_integration_test_present' => $richInputPresent,
            'no_attachment_invariant_covered' => $tripCoversNoAttachment || $richInputCoversNoAttachment,
            'desktop_product_trip_test_path' => self::PATH_DESKTOP_PRODUCT_TRIP_TEST,
            'desktop_rich_input_test_path' => self::PATH_DESKTOP_RICH_INPUT_TEST,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 13 · Desktop Control Plane exposes runtime governance state */
    /* ---------------------------------------------------------------- */

    private function desktopControlPlaneRuntimeUxCheck(): array
    {
        $surfaceSource = $this->source($this->repoPath(self::PATH_DESKTOP_CONTROL_PLANE_SURFACE));
        $cssSource = $this->source($this->repoPath(self::PATH_DESKTOP_CONTROL_PLANE_CSS));

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

        return $this->check('desktop_control_plane_runtime_governance_ux', $passed, 'critical', [
            'reads_external_execution_unsafe_enabled' => $readsUnsafeExternal,
            'reads_external_execution_missing_receipt_bindings' => $readsReceiptGaps,
            'renders_unsafe_execution_metric' => $rendersUnsafeMetric,
            'renders_receipt_gap_metric' => $rendersReceiptMetric,
            'renders_signature_coverage' => $rendersSignatureCoverage,
            'renders_blocked_by_default_policy' => $rendersBlockedPolicy,
            'governance_strip_styled' => $hasGovernanceStrip,
            'surface_path' => self::PATH_DESKTOP_CONTROL_PLANE_SURFACE,
            'css_path' => self::PATH_DESKTOP_CONTROL_PLANE_CSS,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 14 · External execution remains governed, not free-running */
    /* ---------------------------------------------------------------- */

    private function governedExternalExecutionCheck(): array
    {
        $serviceSource = $this->source($this->repoPath(self::PATH_CONTROL_PLANE_SERVICE));
        $testSource = $this->source($this->repoPath(self::PATH_CONTROL_PLANE_TEST));

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

        return $this->check('governed_external_execution_control_plane', $passed, 'critical', [
            'tracks_unsafe_external_execution' => $tracksUnsafeExternal,
            'tracks_missing_receipt_bindings' => $tracksReceiptBindings,
            'unsafe_execution_blocks_runtime_status' => $blocksUnsafeRuntime,
            'manual_handoff_only_policy_present' => $manualHandoffOnly,
            'pending_approval_is_operator_queue_policy_present' => $operatorQueuePolicy,
            'pending_approval_test_present' => $testsPendingApproval,
            'unsafe_execution_blocker_test_present' => $testsUnsafeBlocker,
            'service_path' => self::PATH_CONTROL_PLANE_SERVICE,
            'test_path' => self::PATH_CONTROL_PLANE_TEST,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 14 · Agent Control Plane is the governed agent runtime base */
    /* ---------------------------------------------------------------- */

    private function agentControlPlaneRuntimeStandardCheck(): array
    {
        $orchestratorSource = $this->source($this->repoPath(self::PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR));
        $multiAgentSource = $this->source($this->repoPath(self::PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_CERTIFICATION));
        $taskPacketSource = $this->source($this->repoPath(self::PATH_AGENT_CONTROL_PLANE_TASK_PACKET_BUILDER));
        $leaseSource = $this->source($this->repoPath(self::PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_REPOSITORY));
        $orchestratorTestSource = $this->source($this->repoPath(self::PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR_TEST));
        $multiAgentTestSource = $this->source($this->repoPath(self::PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_TEST));
        $taskPacketTestSource = $this->source($this->repoPath(self::PATH_AGENT_CONTROL_PLANE_TASK_PACKET_TEST));
        $leaseTestSource = $this->source($this->repoPath(self::PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_TEST));

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

        return $this->check('agent_control_plane_runtime_standard', $passed, 'critical', [
            'orchestrates_task_queue_and_claim_leases' => $orchestratesQueueAndLeases,
            'multi_agent_loop_certified' => $multiAgentLoopCertified,
            'task_packets_have_acceptance_and_evidence' => $taskPacketsHaveAcceptanceAndEvidence,
            'leases_govern_ownership_and_disable_dispatch' => $leasesGovernOwnership,
            'orchestrator_test_present' => $testsOrchestrator,
            'multi_agent_loop_test_present' => $testsMultiAgent,
            'task_packet_test_present' => $testsTaskPackets,
            'claim_lease_test_present' => $testsLeases,
            'orchestrator_path' => self::PATH_AGENT_CONTROL_PLANE_ORCHESTRATOR,
            'multi_agent_certification_path' => self::PATH_AGENT_CONTROL_PLANE_MULTI_AGENT_CERTIFICATION,
            'task_packet_builder_path' => self::PATH_AGENT_CONTROL_PLANE_TASK_PACKET_BUILDER,
            'claim_lease_repository_path' => self::PATH_AGENT_CONTROL_PLANE_CLAIM_LEASE_REPOSITORY,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 15 · Internal autonomous software company runtime claim gate */
    /* ---------------------------------------------------------------- */

    private function autonomousCompanyRuntimeCheck(): array
    {
        $serviceSource = $this->source($this->repoPath(self::PATH_ENGINEERING_COMPANY_SERVICE));
        $featureTestSource = $this->source($this->repoPath(self::PATH_ENGINEERING_COMPANY_FEATURE_TEST));
        $unitTestSource = $this->source($this->repoPath(self::PATH_ENGINEERING_COMPANY_UNIT_TEST));

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

        return $this->check('internal_autonomous_company_runtime_claim_gate', $passed, 'critical', [
            'certifies_all_roles_have_agent_task_packets' => $certifiesRoleTaskPackets,
            'internal_autonomous_company_claim_present' => $claimsInternalCompany,
            'external_superiority_claim_blocked' => $blocksExternalSuperiority,
            'external_benchmark_claim_blocked' => $blocksBenchmarkClaim,
            'feature_test_covers_claim_gate' => $featureTestCoversClaim,
            'unit_test_covers_claim_gate' => $unitTestCoversClaim,
            'service_path' => self::PATH_ENGINEERING_COMPANY_SERVICE,
            'feature_test_path' => self::PATH_ENGINEERING_COMPANY_FEATURE_TEST,
            'unit_test_path' => self::PATH_ENGINEERING_COMPANY_UNIT_TEST,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 16 · Capabilities are used, measured and improved by outcome */
    /* ---------------------------------------------------------------- */

    private function capabilityEvolutionLoopCheck(): array
    {
        $controlPlaneSource = $this->source($this->repoPath(self::PATH_CONTROL_PLANE_SERVICE));
        $controlPlaneTestSource = $this->source($this->repoPath(self::PATH_CONTROL_PLANE_TEST));
        $aemorSource = $this->source($this->repoPath(self::PATH_AEMOR_RUNTIME_SERVICE));
        $aemorTestSource = $this->source($this->repoPath(self::PATH_AEMOR_RUNTIME_TEST));
        $factoryRuntimeSource = $this->source($this->repoPath(self::PATH_INTELLIGENCE_FACTORY_RUNTIME_SERVICE));
        $factoryCertSource = $this->source($this->repoPath(self::PATH_INTELLIGENCE_FACTORY_CERTIFICATION_SERVICE));

        $controlPlaneTracksUsage = str_contains($controlPlaneSource, 'intelligence_factory_capability_used_events')
            && str_contains($controlPlaneSource, 'capability_used_events');
        $controlPlaneTracksEvolution = str_contains($controlPlaneSource, 'intelligence_factory_evolution_events')
            && str_contains($controlPlaneSource, 'evolution_events_total');
        $testsControlPlaneCounters = str_contains($controlPlaneTestSource, 'intelligence_factory_capability_used_events')
            && str_contains($controlPlaneTestSource, 'intelligence_factory_evolution_events');
        $aemorCreatesEvolutionCandidate = str_contains($aemorSource, 'intelligenceFactoryEvolution')
            && str_contains($aemorSource, 'atlas_intelligence_factory_evolution_events');
        $aemorTestCoversCandidate = str_contains($aemorTestSource, 'test_close_outcome_with_evidence_creates_intelligence_factory_evolution_candidate');
        $factoryRecordsUsage = str_contains($factoryRuntimeSource, 'capability_used')
            && str_contains($factoryRuntimeSource, 'atlas_intelligence_factory_evolution_events');
        $factoryCertifiesRegistry = str_contains($factoryCertSource, 'atlas_intelligence_factory_capabilities')
            && str_contains($factoryCertSource, 'atlas_intelligence_factory_evolution_events');

        $passed = $controlPlaneTracksUsage && $controlPlaneTracksEvolution
            && $testsControlPlaneCounters && $aemorCreatesEvolutionCandidate
            && $aemorTestCoversCandidate && $factoryRecordsUsage
            && $factoryCertifiesRegistry;

        return $this->check('capability_usage_and_evolution_loop', $passed, 'critical', [
            'control_plane_tracks_capability_used_events' => $controlPlaneTracksUsage,
            'control_plane_tracks_evolution_events' => $controlPlaneTracksEvolution,
            'control_plane_tests_cover_counters' => $testsControlPlaneCounters,
            'aemor_creates_intelligence_factory_evolution_candidate' => $aemorCreatesEvolutionCandidate,
            'aemor_test_covers_evolution_candidate' => $aemorTestCoversCandidate,
            'intelligence_factory_records_usage' => $factoryRecordsUsage,
            'intelligence_factory_certifies_registry_and_evolution_tables' => $factoryCertifiesRegistry,
            'control_plane_service_path' => self::PATH_CONTROL_PLANE_SERVICE,
            'aemor_service_path' => self::PATH_AEMOR_RUNTIME_SERVICE,
            'intelligence_factory_service_path' => self::PATH_INTELLIGENCE_FACTORY_RUNTIME_SERVICE,
        ]);
    }

    private function verifiedContextExecutionLoopCheck(): array
    {
        $serviceSource = $this->source($this->repoPath(self::PATH_AVCEL_SERVICE));
        $commandSource = $this->source($this->repoPath(self::PATH_AVCEL_COMMAND));
        $testSource = $this->source($this->repoPath(self::PATH_AVCEL_TEST));
        $docSource = $this->source($this->repoPath(self::PATH_AVCEL_DOC));

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

        return $this->check('verified_context_execution_loop', $passed, 'critical', [
            'service_connects_context_cache_token_economy_alve_aemor' => $serviceConnectsCoreRuntimes,
            'service_declares_read_only_policy' => $serviceDeclaresReadOnlyPolicy,
            'service_defines_eight_stage_loop' => $serviceDefinesEightStages,
            'command_present' => $commandPresent,
            'tests_cover_core_invariants' => $testCoversCoreInvariants,
            'canonical_doc_present' => $docPresent,
            'service_path' => self::PATH_AVCEL_SERVICE,
            'command_path' => self::PATH_AVCEL_COMMAND,
            'test_path' => self::PATH_AVCEL_TEST,
            'doc_path' => self::PATH_AVCEL_DOC,
        ]);
    }

    private function codeIntelligenceAutomaticGateCheck(): array
    {
        $serviceSource = $this->source($this->repoPath(self::PATH_CODE_INTELLIGENCE_GATE_SERVICE));
        $commandSource = $this->source($this->repoPath('app/Console/Commands/AtlasEngineeringKnowledgeCommand.php'));
        $programmingGateSource = $this->source($this->repoPath(self::PATH_PROGRAMMING_CODE_INTELLIGENCE_GATE));
        $sessionBootstrapSource = $this->source($this->repoPath('app/Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php'));
        $testSource = $this->source($this->repoPath(self::PATH_CODE_INTELLIGENCE_GATE_TEST));
        $docSource = $this->source($this->repoPath(self::PATH_CODE_INTELLIGENCE_DOC));

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

        return $this->check('code_intelligence_automatic_gate', $passed, 'critical', [
            'service_fail_closed' => $serviceFailClosed,
            'service_covers_required_consumers' => $serviceCoversConsumers,
            'command_wired' => $commandWired,
            'atlas_dev_gate_wired' => $devGateWired,
            'session_bootstrap_wired' => $bootstrapWired,
            'tests_cover_stale_and_consumers' => $testsCover,
            'doc_covers_gate' => $docCovers,
            'service_path' => self::PATH_CODE_INTELLIGENCE_GATE_SERVICE,
            'test_path' => self::PATH_CODE_INTELLIGENCE_GATE_TEST,
            'doc_path' => self::PATH_CODE_INTELLIGENCE_DOC,
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
            && str_contains($serviceSource, 'ready_for_assisted_execution')
            && str_contains($serviceSource, 'needs_context');
        $serviceRoutesDevAndForge = str_contains($serviceSource, "'atlas_dev'")
            && str_contains($serviceSource, "'atlas_forge'")
            && str_contains($serviceSource, "'programming.repair'")
            && str_contains($serviceSource, "'programming.forge'");
        $serviceUsesDevRuntimeGate = str_contains($serviceSource, 'DevRuntimeIntelligenceService')
            && str_contains($serviceSource, 'provider_safe')
            && str_contains($serviceSource, 'dev_context_not_provider_safe');
        $serviceProtectsHumanBugPath = str_contains($serviceSource, 'Login|Auth|Session')
            && str_contains($serviceSource, 'workspace_required')
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
        $testsCover = str_contains($testSource, 'test_login_bug_human_request_builds_provider_safe_dev_envelope')
            && str_contains($testSource, 'test_missing_workspace_blocks_before_provider')
            && str_contains($testSource, 'test_large_obra_request_routes_to_forge_without_dev_preview')
            && str_contains($testSource, 'test_hash_is_deterministic_for_same_input');
        $docPresent = str_contains($docSource, 'runtime_acronym: AAEQ')
            && str_contains($docSource, 'Pedido humano nunca vira provider call bruto')
            && str_contains($docSource, 'Dev so executa se `provider_safe=true`');

        $passed = $serviceBuildsHumanEnvelope && $serviceRoutesDevAndForge
            && $serviceUsesDevRuntimeGate && $serviceProtectsHumanBugPath
            && $controllerWired && $controllerEnforcesGate && $testsCover && $docPresent;

        return $this->check('assisted_execution_quality', $passed, 'critical', [
            'service_builds_human_execution_envelope' => $serviceBuildsHumanEnvelope,
            'service_routes_dev_and_forge' => $serviceRoutesDevAndForge,
            'service_uses_dev_runtime_context_gate' => $serviceUsesDevRuntimeGate,
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
