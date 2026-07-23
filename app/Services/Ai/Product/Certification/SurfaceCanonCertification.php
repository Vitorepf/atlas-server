<?php

namespace App\Services\Ai\Product\Certification;

use App\Services\Ai\Product\AtlasAiProductCertificationService;

/**
 * Atlas AI · Product Certification — Surface Canon section.
 *
 * Checks 1-12: the rich-input canon is wired end-to-end across every product
 * surface (Hyperflow entry, /ai/interactions, Universal Composer, Mobile,
 * Desktop, Forge) plus the cross-surface presentation/context/routing canon.
 *
 * Bodies moved verbatim from AtlasAiProductCertificationService; the only
 * rewrites are shared-helper calls (-> $this->support->*) and const references
 * (self::PATH_* -> AtlasAiProductCertificationService::PATH_*).
 */
final class SurfaceCanonCertification
{
    public function __construct(private readonly CertificationSupport $support)
    {
    }

    /* ---------------------------------------------------------------- */
    /* Check 1 · Hyperflow V2 entry present + 5-stage pipeline */
    /* ---------------------------------------------------------------- */

    public function hyperflowV2EntryCheck(): array
    {
        $source = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_HYPERFLOW_ENTRY));
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

        return $this->support->check('hyperflow_v2_entry', $passed, 'critical', [
            'entry_class_present' => $entryClass,
            'envelope_schema_version_present' => $envelopeCanon,
            'pipeline_stages' => $pipelineStages,
            'evidence_path' => AtlasAiProductCertificationService::PATH_HYPERFLOW_ENTRY,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 2 · /ai/interactions preserves rich_input_payload before */
    /*           the Hyperflow entry runs */
    /* ---------------------------------------------------------------- */

    public function aiInteractionsPreservesRichInputCheck(): array
    {
        $source = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_AI_INTERACTION_CONTROLLER));
        $extractsRichInput = str_contains($source, "\$data['rich_input_payload'] ?? null");
        $mergesIntoPayload = str_contains($source, 'payloadWithRichInputPayload');
        $passesToHyperflow = str_contains($source, '$hyperflowEntry->run($data)');
        $mergeBeforeRun = $this->support->callOrderInSource($source, 'payloadWithRichInputPayload', '$hyperflowEntry->run');

        $requestSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_STORE_AI_INTERACTION_REQUEST));
        $validatesSchema = str_contains($requestSource, 'rich_input_payload.schema_version');
        $validatesShape = str_contains($requestSource, 'rich_input_payload.uploaded_image_ids')
            && str_contains($requestSource, 'rich_input_payload.uploaded_document_ids')
            && str_contains($requestSource, 'rich_input_payload.source_manifest');

        $passed = $extractsRichInput && $mergesIntoPayload && $passesToHyperflow
            && $mergeBeforeRun && $validatesSchema && $validatesShape;

        return $this->support->check('ai_interactions_preserves_rich_input', $passed, 'critical', [
            'controller_extracts_rich_input' => $extractsRichInput,
            'controller_merges_into_payload' => $mergesIntoPayload,
            'controller_passes_to_hyperflow' => $passesToHyperflow,
            'merge_happens_before_hyperflow_run' => $mergeBeforeRun,
            'request_validates_schema_version' => $validatesSchema,
            'request_validates_canonical_shape' => $validatesShape,
            'controller_path' => AtlasAiProductCertificationService::PATH_AI_INTERACTION_CONTROLLER,
            'request_path' => AtlasAiProductCertificationService::PATH_STORE_AI_INTERACTION_REQUEST,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 3 · Universal Composer canon package present + exports */
    /* ---------------------------------------------------------------- */

    public function universalComposerCanonCheck(): array
    {
        $indexPath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_CANON_PACKAGE);
        $typesPath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_CANON_TYPES);
        $packagePresent = is_file($indexPath) && is_file($typesPath);
        $typesSource = $this->support->source($typesPath);
        $schemaConstant = str_contains($typesSource, "'atlas.rich_input.payload.v1'")
            && str_contains($typesSource, 'ATLAS_RICH_INPUT_PAYLOAD_SCHEMA');
        $payloadType = str_contains($typesSource, 'AtlasRichInputPayload')
            && str_contains($typesSource, 'source_manifest')
            && str_contains($typesSource, 'url_attachments')
            && str_contains($typesSource, 'text_blocks');

        $indexSource = $this->support->source($indexPath);
        $exportsBuilders = str_contains($indexSource, 'buildRichInputPayload')
            && str_contains($indexSource, 'buildSourceManifest')
            && str_contains($indexSource, 'estimateRichInputTokens');

        $passed = $packagePresent && $schemaConstant && $payloadType && $exportsBuilders;

        return $this->support->check('universal_composer_canon_present', $passed, 'critical', [
            'package_files_present' => $packagePresent,
            'schema_constant_declared' => $schemaConstant,
            'payload_type_complete' => $payloadType,
            'builders_exported' => $exportsBuilders,
            'package_path' => AtlasAiProductCertificationService::PATH_CANON_PACKAGE,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 4 · Mobile (atlas-app) uses canon */
    /* ---------------------------------------------------------------- */

    public function mobileUsesCanonCheck(): array
    {
        // Mobile re-exports the canon through `lib/richInput/*` (barrel pattern
        // identical to the Desktop). The api client then imports the local
        // barrel — this is the canon usage we certify.
        $barrelSourceManifest = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_MOBILE_RICH_INPUT_BARREL));
        $barrelTypes = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_MOBILE_RICH_INPUT_TYPES));
        $barrelReExportsCanon = ($this->support->importsRichInputCanon($barrelSourceManifest)
            && $this->support->importsRichInputCanon($barrelTypes));

        $clientSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_MOBILE_CANON_IMPORT));
        $usesBuilder = str_contains($clientSource, 'buildRichInputPayload');
        $sendsToInteractions = str_contains($clientSource, "'/ai/interactions'")
            && str_contains($clientSource, 'rich_input_payload');

        $passed = $barrelReExportsCanon && $usesBuilder && $sendsToInteractions;

        return $this->support->check('mobile_uses_canon', $passed, 'critical', [
            'mobile_barrel_reexports_canon_package' => $barrelReExportsCanon,
            'mobile_client_uses_canon_builder' => $usesBuilder,
            'mobile_client_sends_rich_input_payload_to_interactions' => $sendsToInteractions,
            'mobile_barrel_path' => AtlasAiProductCertificationService::PATH_MOBILE_RICH_INPUT_BARREL,
            'mobile_types_path' => AtlasAiProductCertificationService::PATH_MOBILE_RICH_INPUT_TYPES,
            'mobile_client_path' => AtlasAiProductCertificationService::PATH_MOBILE_CANON_IMPORT,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 5 · Desktop uses canon (barrel re-exports the package) */
    /* ---------------------------------------------------------------- */

    public function desktopUsesCanonCheck(): array
    {
        $barrelPath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_RICH_INPUT_BARREL);
        $typesPath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_RICH_INPUT_TYPES);
        $barrelPresent = is_file($barrelPath);
        $typesPresent = is_file($typesPath);
        $typesSource = $this->support->source($typesPath);
        $reExportsCanon = str_contains($typesSource, "from '@atlas/rich-input-canon'");

        $useHyperflowSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_USE_HYPERFLOW));
        $useHyperflowPresent = is_file($this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_USE_HYPERFLOW))
            && str_contains($useHyperflowSource, 'export function useHyperflowRuntime')
            && str_contains($useHyperflowSource, 'export function buildHyperflowRuntimeView');

        $passed = $barrelPresent && $typesPresent && $reExportsCanon && $useHyperflowPresent;

        return $this->support->check('desktop_uses_canon', $passed, 'critical', [
            'desktop_rich_input_barrel_present' => $barrelPresent,
            'desktop_types_present' => $typesPresent,
            'desktop_types_reexport_canon_package' => $reExportsCanon,
            'desktop_hyperflow_view_model_hook_present' => $useHyperflowPresent,
            'barrel_path' => AtlasAiProductCertificationService::PATH_DESKTOP_RICH_INPUT_BARREL,
            'hook_path' => AtlasAiProductCertificationService::PATH_DESKTOP_USE_HYPERFLOW,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 6 · Forge accepts payload canon */
    /* ---------------------------------------------------------------- */

    public function forgeAcceptsCanonCheck(): array
    {
        $intakeSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_FORGE_INTAKE_SERVICE));
        $hasNormalizer = str_contains($intakeSource, 'private function normalizeRichInputPayload');
        $defaultsCanonSchema = str_contains($intakeSource, "'atlas.rich_input.payload.v1'");
        $persistsSchemaVersion = str_contains($intakeSource, 'rich_input_schema_version');

        $workControllerSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_FORGE_WORK_CONTROLLER));
        $controllerNormalises = str_contains($workControllerSource, 'normaliseRichInput')
            || str_contains($workControllerSource, 'normalizeRichInputPayload')
            || str_contains($workControllerSource, 'rich_input');
        $intakeReadyNotAutoFromAttachment = str_contains($workControllerSource, "'forge_work_intake_ready'")
            && (str_contains($workControllerSource, "'forge_work_intake_ready' => false")
                || str_contains($workControllerSource, '\'forge_work_intake_ready\' => false'));

        $passed = $hasNormalizer && $defaultsCanonSchema && $persistsSchemaVersion
            && $controllerNormalises && $intakeReadyNotAutoFromAttachment;

        return $this->support->check('forge_accepts_canon', $passed, 'critical', [
            'intake_service_has_normalizer' => $hasNormalizer,
            'intake_service_defaults_canon_schema' => $defaultsCanonSchema,
            'intake_service_persists_schema_version' => $persistsSchemaVersion,
            'work_controller_normalises_rich_input' => $controllerNormalises,
            'forge_work_intake_ready_is_not_set_by_attachment_alone' => $intakeReadyNotAutoFromAttachment,
            'intake_service_path' => AtlasAiProductCertificationService::PATH_FORGE_INTAKE_SERVICE,
            'controller_path' => AtlasAiProductCertificationService::PATH_FORGE_WORK_CONTROLLER,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 7 · Presentation Contract present on both surfaces and */
    /*           wired into the message body renderer */
    /* ---------------------------------------------------------------- */

    public function presentationContractCheck(): array
    {
        $mobilePath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_MOBILE_PRESENTATION);
        $desktopPath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_PRESENTATION);
        $mobilePresent = is_file($mobilePath);
        $desktopPresent = is_file($desktopPath);

        $mobileSource = $this->support->source($mobilePath);
        $desktopSource = $this->support->source($desktopPath);
        $mobileExportsFn = str_contains($mobileSource, 'export function projectPresentation');
        $desktopExportsFn = str_contains($desktopSource, 'export function projectPresentation');
        $mobileSeparatesTechnical = str_contains($mobileSource, 'ALWAYS_METADATA_SECTIONS')
            || str_contains($mobileSource, 'source_refs');
        $desktopSeparatesTechnical = str_contains($desktopSource, 'ALWAYS_METADATA_SECTIONS')
            && str_contains($desktopSource, 'source_refs')
            && str_contains($desktopSource, 'uncertainty');

        $mobileTurnSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_MOBILE_TURN_MODEL));
        $mobileWired = str_contains($mobileTurnSource, 'projectPresentation');

        $desktopConversationSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_CONVERSATION));
        $desktopWired = str_contains($desktopConversationSource, 'projectPresentation');

        $passed = $mobilePresent && $desktopPresent
            && $mobileExportsFn && $desktopExportsFn
            && $mobileSeparatesTechnical && $desktopSeparatesTechnical
            && $mobileWired && $desktopWired;

        return $this->support->check('presentation_contract', $passed, 'critical', [
            'mobile_module_present' => $mobilePresent,
            'desktop_module_present' => $desktopPresent,
            'mobile_exports_project_presentation' => $mobileExportsFn,
            'desktop_exports_project_presentation' => $desktopExportsFn,
            'mobile_separates_technical_sections' => $mobileSeparatesTechnical,
            'desktop_separates_technical_sections' => $desktopSeparatesTechnical,
            'mobile_wired_in_turn_model' => $mobileWired,
            'desktop_wired_in_conversation' => $desktopWired,
            'mobile_path' => AtlasAiProductCertificationService::PATH_MOBILE_PRESENTATION,
            'desktop_path' => AtlasAiProductCertificationService::PATH_DESKTOP_PRESENTATION,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 8 · Context/Trace/Audit consumes technical data */
    /* ---------------------------------------------------------------- */

    public function contextTraceAuditCheck(): array
    {
        $desktopContextSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_CONTEXT_PANEL));
        $desktopAuditSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_RESPONSE_AUDIT));

        $desktopContextReadsViewModel = str_contains($desktopContextSource, 'useHyperflowRuntime')
            && str_contains($desktopContextSource, 'hyperflow.flowId')
            && str_contains($desktopContextSource, 'hyperflow.intent');
        $desktopAuditConsumesSections = str_contains($desktopAuditSource, 'sections: Record<string, string[]>')
            && str_contains($desktopAuditSource, 'source_refs');

        $mobileContextSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_MOBILE_CONTEXT_SHEET));
        $mobileContextConsumes = is_file($this->support->repoPath(AtlasAiProductCertificationService::PATH_MOBILE_CONTEXT_SHEET))
            && (str_contains($mobileContextSource, 'AtlasAiContextSheet')
                || str_contains($mobileContextSource, 'context'));

        $passed = $desktopContextReadsViewModel && $desktopAuditConsumesSections && $mobileContextConsumes;

        return $this->support->check('context_trace_audit_consumes_technical_data', $passed, 'critical', [
            'desktop_context_panel_reads_hyperflow_view_model' => $desktopContextReadsViewModel,
            'desktop_response_audit_consumes_sections' => $desktopAuditConsumesSections,
            'mobile_context_sheet_present' => $mobileContextConsumes,
            'desktop_context_panel_path' => AtlasAiProductCertificationService::PATH_DESKTOP_CONTEXT_PANEL,
            'desktop_audit_path' => AtlasAiProductCertificationService::PATH_DESKTOP_RESPONSE_AUDIT,
            'mobile_context_sheet_path' => AtlasAiProductCertificationService::PATH_MOBILE_CONTEXT_SHEET,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 9 · Specialist flows registered + canon flow ids exist */
    /* ---------------------------------------------------------------- */

    public function specialistFlowsRegisteredCheck(): array
    {
        $flowRouterPresent = is_file($this->support->repoPath(AtlasAiProductCertificationService::PATH_FLOW_ROUTER));
        $readinessPath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_SPECIALIST_FLOWS_READINESS);
        $readinessPresent = is_file($readinessPath);
        $readinessSource = $this->support->source($readinessPath);
        $registersFlows = str_contains($readinessSource, 'AtlasAiSpecialistFlowExecutionService')
            || str_contains($readinessSource, 'AtlasAiSpecialistFlowRuntimeService');

        $canonSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_ROUTER_CANON));
        $declaresFlows = str_contains($canonSource, 'specialist flow_ids')
            || str_contains($canonSource, 'SpecialistFlows registry');

        $passed = $flowRouterPresent && $readinessPresent && $registersFlows && $declaresFlows;

        return $this->support->check('specialist_flows_registered', $passed, 'critical', [
            'flow_router_present' => $flowRouterPresent,
            'specialist_flows_readiness_service_present' => $readinessPresent,
            'readiness_references_specialist_runtime' => $registersFlows,
            'router_canon_declares_specialist_flow_ids' => $declaresFlows,
            'flow_router_path' => AtlasAiProductCertificationService::PATH_FLOW_ROUTER,
            'readiness_path' => AtlasAiProductCertificationService::PATH_SPECIALIST_FLOWS_READINESS,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 10 · Anti-regression routing tests guard the canonical */
    /*            routing decisions Atlas AI relies on */
    /* ---------------------------------------------------------------- */

    public function routingAntiRegressionCheck(): array
    {
        $desktopAntiRegPath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_ANTIREGRESSION);
        $integrationPath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_INTEGRATION_TEST);
        $antiRegPresent = is_file($desktopAntiRegPath);
        $integrationPresent = is_file($integrationPath);

        $antiRegSource = $this->support->source($desktopAntiRegPath);
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

        return $this->support->check('routing_anti_regression_tests_present', $passed, 'critical', [
            'desktop_anti_regression_test_present' => $antiRegPresent,
            'hyperflow_entry_integration_test_present' => $integrationPresent,
            'covers_research_not_programming_dev' => $coversResearch,
            'covers_finance_not_atlas_dev' => $coversFinance,
            'covers_programming_routes_to_atlas_dev' => $coversProgramming,
            'covers_obra_handoff_to_forge' => $coversObra,
            'desktop_anti_regression_path' => AtlasAiProductCertificationService::PATH_DESKTOP_ANTIREGRESSION,
            'integration_test_path' => AtlasAiProductCertificationService::PATH_DESKTOP_INTEGRATION_TEST,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 11 · Forge strips raw text → content_hash and derives */
    /*            context_refs from source_manifest */
    /* ---------------------------------------------------------------- */

    public function forgeHashAndContextRefsCheck(): array
    {
        $intakeSource = $this->support->source($this->support->repoPath(AtlasAiProductCertificationService::PATH_FORGE_INTAKE_SERVICE));
        $hashesUrl = str_contains($intakeSource, "hash('sha256', \$attachment['url'])");
        $hashesContent = str_contains($intakeSource, "hash('sha256', \$block['content'])");
        $derivesContextRefs = str_contains($intakeSource, 'url_attachment:')
            && str_contains($intakeSource, 'text_block:')
            && str_contains($intakeSource, 'content_hash');
        $forgeTestPresent = is_file($this->support->repoPath(AtlasAiProductCertificationService::PATH_FORGE_RICH_INPUT_TEST))
            && is_file($this->support->repoPath(AtlasAiProductCertificationService::PATH_FORGE_INTAKE_TEST));

        $passed = $hashesUrl && $hashesContent && $derivesContextRefs && $forgeTestPresent;

        return $this->support->check('forge_strips_raw_text_to_hash_and_derives_context_refs', $passed, 'critical', [
            'intake_hashes_url_attachments' => $hashesUrl,
            'intake_hashes_text_block_content' => $hashesContent,
            'intake_derives_context_refs_from_manifest' => $derivesContextRefs,
            'forge_rich_input_tests_present' => $forgeTestPresent,
            'intake_service_path' => AtlasAiProductCertificationService::PATH_FORGE_INTAKE_SERVICE,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Check 12 · No-attachment flow continues to work (warn, not block) */
    /* ---------------------------------------------------------------- */

    public function noAttachmentPathStillWorksCheck(): array
    {
        $tripPath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_PRODUCT_TRIP_TEST);
        $richInputPath = $this->support->repoPath(AtlasAiProductCertificationService::PATH_DESKTOP_RICH_INPUT_TEST);
        $tripPresent = is_file($tripPath);
        $richInputPresent = is_file($richInputPath);
        $tripSource = $this->support->source($tripPath);
        $richInputSource = $this->support->source($richInputPath);
        $tripCoversNoAttachment = str_contains($tripSource, 'fluxo sem anexo')
            || str_contains($tripSource, 'sem rich_input_payload');
        $richInputCoversNoAttachment = str_contains($richInputSource, 'SEM anexos')
            || str_contains($richInputSource, 'sem anexos');

        $passed = $tripPresent && $richInputPresent
            && ($tripCoversNoAttachment || $richInputCoversNoAttachment);

        return $this->support->check('no_attachment_path_still_works', $passed, 'warn', [
            'desktop_product_trip_test_present' => $tripPresent,
            'desktop_rich_input_integration_test_present' => $richInputPresent,
            'no_attachment_invariant_covered' => $tripCoversNoAttachment || $richInputCoversNoAttachment,
            'desktop_product_trip_test_path' => AtlasAiProductCertificationService::PATH_DESKTOP_PRODUCT_TRIP_TEST,
            'desktop_rich_input_test_path' => AtlasAiProductCertificationService::PATH_DESKTOP_RICH_INPUT_TEST,
        ]);
    }
}
