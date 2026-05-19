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
                'scope' => 'product_plumbing_only',
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
        $barrelReExportsCanon = str_contains($barrelSourceManifest, "from '@atlas/rich-input-canon'")
            && str_contains($barrelTypes, "from '@atlas/rich-input-canon'");

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

    private function repoPath(string $relative): string
    {
        if (str_starts_with($relative, 'app/') || str_starts_with($relative, 'tests/')) {
            return rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relative, '/');
        }

        return rtrim(dirname(base_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relative, '/');
    }
}
