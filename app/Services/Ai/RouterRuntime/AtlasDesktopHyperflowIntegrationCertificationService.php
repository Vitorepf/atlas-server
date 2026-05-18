<?php

namespace App\Services\Ai\RouterRuntime;

use Illuminate\Support\Facades\File;

/**
 * Slim certification for the Atlas Desktop AI ↔ Hyperflow ↔ Rich Input
 * integration. Scoped intentionally narrow: it does NOT replicate
 * `AtlasAiHyperflowCertificationService`'s rivals battery / external
 * evidence gate (those exist to certify the Claude Code/Codex replacement
 * claim and are deliberately conservative). This service certifies the
 * **plumbing** that lets the Desktop consume the Router Runtime decision
 * and share the Rich Input capability across surfaces.
 *
 * Four canonical checks (see `atlas-hyperflow-operation.md#Atlas Desktop AI Hyperflow Integration Canon`):
 *  - `desktop_hyperflow_runtime_integration`
 *  - `atlas_rich_input_shared_runtime`
 *  - `forge_rich_input_adapter`
 *  - `no_legacy_programming_dev_default`
 *
 * No provider invocation, no rivals, no benchmark. File-inspection based
 * so it's safe to run inside CI / certification pipelines.
 */
class AtlasDesktopHyperflowIntegrationCertificationService
{
    public const SCHEMA_VERSION = 'atlas.ai.desktop_hyperflow_integration_certification.v1';

    public const DOC_PATH = 'docs/engineering-knowledge-base/atlas-hyperflow-operation.md';

    public const DESKTOP_USE_ATLAS_AI = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/useAtlasAi.ts';

    public const DESKTOP_CONTRACT = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts';

    public const DESKTOP_RICH_INPUT_BARREL = 'atlas-desktop/apps/desktop/src/lib/rich-input/index.ts';

    public const DESKTOP_RICH_INPUT_DIR = 'atlas-desktop/apps/desktop/src/lib/rich-input';

    public const DESKTOP_BRIDGE = 'atlas-desktop/apps/desktop/src/lib/bridge.ts';

    public const FORGE_OBRA_BAR = 'atlas-desktop/apps/desktop/src/surfaces/code/obra/ObraBar.tsx';

    public const FORGE_MAIN_STAGE = 'atlas-desktop/apps/desktop/src/surfaces/code/stage/MainStage.tsx';

    public const FORGE_RICH_INPUT_HOOK = 'atlas-desktop/apps/desktop/src/lib/rich-input/useAtlasRichInputAttachments.ts';

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->desktopHyperflowRuntimeIntegrationCheck(),
            $this->atlasRichInputSharedRuntimeCheck(),
            $this->forgeRichInputAdapterCheck(),
            $this->noLegacyProgrammingDevDefaultCheck(),
        ];

        $failed = array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) !== 'passed'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'passed' : 'blocked',
            'summary' => [
                'total' => count($checks),
                'passed' => count($checks) - count($failed),
                'failed' => count($failed),
            ],
            'checks' => $checks,
            'remaining_blockers' => array_map(
                static fn (array $check): string => (string) ($check['id'] ?? 'unknown_check'),
                $failed,
            ),
            'writes' => false,
            'declares_teos' => false,
            'declares_benchmark' => false,
        ];
    }

    /**
     * Check 1: the canonical entry path is wired and observable.
     *
     * @return array<string,mixed>
     */
    private function desktopHyperflowRuntimeIntegrationCheck(): array
    {
        $orchestratorPath = app_path('Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php');
        $controllerSource = $this->source(app_path('Http/Controllers/AiInteractionController.php'));
        $gatewaySource = $this->source(app_path('Services/Ai/AiGatewayService.php'));
        $resourceSource = $this->source(app_path('Http/Resources/AiTraceResource.php'));

        $orchestratorPresent = is_file($orchestratorPath);
        $controllerWires = str_contains($controllerSource, 'AtlasHyperflowEntryService')
            && str_contains($controllerSource, '$hyperflowEntry->run($data)');
        $orchestratorBeforeLegacyRouter = $this->controllerCallOrder(
            $controllerSource,
            '$hyperflowEntry->run',
            '$this->applyAtlasAiRouterDecision',
        );
        $gatewayPropagates = str_contains($gatewaySource, "data_get(\$options, 'payload.hyperflow_runtime')");
        $resourceExposesRich = str_contains($resourceSource, "'hyperflow_runtime'")
            && str_contains($resourceSource, 'hyperflowRuntimeForResponse');
        $resourceExposesFlat = str_contains($resourceSource, "'hyperflow' =>")
            && str_contains($resourceSource, 'hyperflowFlatForResponse');

        $passed = $orchestratorPresent
            && $controllerWires
            && $orchestratorBeforeLegacyRouter
            && $gatewayPropagates
            && $resourceExposesRich
            && $resourceExposesFlat;

        return $this->check('desktop_hyperflow_runtime_integration', $passed, [
            'orchestrator_present' => $orchestratorPresent,
            'controller_wires_orchestrator' => $controllerWires,
            'orchestrator_runs_before_legacy_router' => $orchestratorBeforeLegacyRouter,
            'gateway_propagates_hyperflow_runtime' => $gatewayPropagates,
            'resource_exposes_hyperflow_runtime' => $resourceExposesRich,
            'resource_exposes_flat_hyperflow' => $resourceExposesFlat,
            'orchestrator_file' => 'app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php',
            'controller_file' => 'app/Http/Controllers/AiInteractionController.php',
            'resource_file' => 'app/Http/Resources/AiTraceResource.php',
        ]);
    }

    /**
     * Check 2: Atlas Rich Input is a single shared capability on the
     * Desktop, with backend caps aligned and the canonical statement
     * declared in the operation doc.
     *
     * @return array<string,mixed>
     */
    private function atlasRichInputSharedRuntimeCheck(): array
    {
        $barrelPath = $this->repoPath(self::DESKTOP_RICH_INPUT_BARREL);
        $barrelSource = $this->source($barrelPath);
        $hasCanonicalExports = $barrelPath !== '' && is_file($barrelPath)
            && str_contains($barrelSource, './useAtlasRichInputAttachments')
            && str_contains($barrelSource, './imageProcessor')
            && str_contains($barrelSource, './pdfProcessor')
            && str_contains($barrelSource, './metrics')
            && str_contains($barrelSource, './types');

        $dirPath = $this->repoPath(self::DESKTOP_RICH_INPUT_DIR);
        $expectedFiles = ['chunkedUploader.ts', 'imageProcessor.ts', 'pdfProcessor.ts', 'urlDetector.ts', 'types.ts', 'metrics.ts', 'useAtlasRichInputAttachments.ts'];
        $missingFiles = [];
        foreach ($expectedFiles as $file) {
            if (! is_file($dirPath.'/'.$file)) {
                $missingFiles[] = $file;
            }
        }

        $docPath = base_path(self::DOC_PATH);
        $docSource = $this->source($docPath);
        $docCarriesCanon = str_contains($docSource, 'Atlas Unified Rich Input Runtime')
            && str_contains($docSource, 'capability **compartilhada**')
            && str_contains($docSource, 'Atlas Desktop AI Hyperflow Integration Canon');

        $storeRequestSource = $this->source(app_path('Http/Requests/StoreAiInteractionRequest.php'));
        $backendCapsAligned = preg_match("/'uploaded_images'\s*=>\s*\[[^\]]*'max:8'/", $storeRequestSource) === 1
            && preg_match("/'uploaded_documents'\s*=>\s*\[[^\]]*'max:4'/", $storeRequestSource) === 1;

        $passed = $hasCanonicalExports && $missingFiles === [] && $docCarriesCanon && $backendCapsAligned;

        return $this->check('atlas_rich_input_shared_runtime', $passed, [
            'desktop_barrel_present' => $hasCanonicalExports,
            'desktop_missing_canonical_files' => $missingFiles,
            'doc_carries_canon_section' => $docCarriesCanon,
            'backend_caps_aligned_8_imgs_4_docs' => $backendCapsAligned,
            'barrel_path' => self::DESKTOP_RICH_INPUT_BARREL,
            'doc_path' => self::DOC_PATH,
        ]);
    }

    /**
     * Check 3: Forge consumes the same Rich Input runtime — proven by the
     * canonical adapter files that import from the unified library, and by
     * the Forge work controller accepting `rich_input.*` payload keys that
     * mirror the Desktop's UploadOutput shape.
     *
     * @return array<string,mixed>
     */
    private function forgeRichInputAdapterCheck(): array
    {
        $forgeHookPath = $this->repoPath(self::FORGE_RICH_INPUT_HOOK);
        $forgeObraBarPath = $this->repoPath(self::FORGE_OBRA_BAR);
        $forgeMainStagePath = $this->repoPath(self::FORGE_MAIN_STAGE);
        $bridgePath = $this->repoPath(self::DESKTOP_BRIDGE);
        $forgeFilesPresent = is_file($forgeHookPath) && is_file($forgeObraBarPath) && is_file($forgeMainStagePath) && is_file($bridgePath);

        // Forge/Obra must consume the canonical shared hook directly, not the
        // old Atlas AI compatibility shim. This proves the adapter is not
        // coupled to the Atlas AI surface.
        $forgeObraBarSource = $this->source($forgeObraBarPath);
        $forgeMainStageSource = $this->source($forgeMainStagePath);
        $forgeUsesCanonicalHook = str_contains($forgeObraBarSource, 'useAtlasRichInputAttachments')
            && str_contains($forgeObraBarSource, "../../../lib/rich-input")
            && str_contains($forgeMainStageSource, 'useAtlasRichInputAttachments')
            && str_contains($forgeMainStageSource, "../../../lib/rich-input");
        $forgeUsesSharedTokenEstimator = str_contains($this->source($this->repoPath('atlas-desktop/apps/desktop/src/surfaces/code/obra/RichInputControls.tsx')), 'estimateRichInputTokens');

        $forgeWorkControllerSource = $this->source(app_path('Http/Controllers/AtlasCodeWorkController.php'));
        $forgeAcceptsRichInput = str_contains($forgeWorkControllerSource, "'rich_input'")
            && str_contains($forgeWorkControllerSource, "'rich_input.uploaded_images'")
            && str_contains($forgeWorkControllerSource, "'rich_input.uploaded_documents'");

        $bridgeSource = $this->source($bridgePath);
        $bridgeDoesNotSilentlyDropRichInput = str_contains($bridgeSource, "requireHttpRichInputBridge('createObra', richInput)")
            && str_contains($bridgeSource, "requireHttpRichInputBridge('sendIntent', richInput)")
            && str_contains($bridgeSource, "MODE === 'http' || (MODE === 'tauri' && richInput)");

        // Anti-duplication invariant: no other surface ships its own
        // attachments/ runtime. We scan surfaces/* for a parallel folder.
        $surfacesDir = $this->repoPath('atlas-desktop/apps/desktop/src/surfaces');
        $parallelAttachmentDirs = [];
        if (is_dir($surfacesDir)) {
            foreach (scandir($surfacesDir) ?: [] as $entry) {
                if (in_array($entry, ['.', '..', 'atlas-ai'], true)) {
                    continue;
                }
                $candidate = $surfacesDir.'/'.$entry.'/attachments';
                if (is_dir($candidate)) {
                    $parallelAttachmentDirs[] = $entry;
                }
            }
        }

        $passed = $forgeFilesPresent
            && $forgeUsesCanonicalHook
            && $forgeUsesSharedTokenEstimator
            && $forgeAcceptsRichInput
            && $bridgeDoesNotSilentlyDropRichInput
            && $parallelAttachmentDirs === [];

        return $this->check('forge_rich_input_adapter', $passed, [
            'forge_hook_present' => is_file($forgeHookPath),
            'forge_obra_bar_present' => is_file($forgeObraBarPath),
            'forge_main_stage_present' => is_file($forgeMainStagePath),
            'forge_uses_canonical_hook' => $forgeUsesCanonicalHook,
            'forge_uses_shared_token_estimator' => $forgeUsesSharedTokenEstimator,
            'forge_work_controller_accepts_rich_input' => $forgeAcceptsRichInput,
            'desktop_bridge_preserves_rich_input' => $bridgeDoesNotSilentlyDropRichInput,
            'parallel_attachments_runtimes' => $parallelAttachmentDirs,
        ]);
    }

    /**
     * Check 4: the Desktop opens in auto/auto and never silently degrades
     * to `programming.dev`. Asserted by static inspection on contract.ts +
     * useAtlasAi.ts (we don't want to require a JS test runner in this
     * cert, so we read the source files directly).
     *
     * @return array<string,mixed>
     */
    private function noLegacyProgrammingDevDefaultCheck(): array
    {
        $contractSource = $this->source($this->repoPath(self::DESKTOP_CONTRACT));
        $useAtlasAiSource = $this->source($this->repoPath(self::DESKTOP_USE_ATLAS_AI));

        // `auto` must be the first entry of MODE_OPTIONS. The array literal
        // sits AFTER a multi-generic type annotation that contains `]`, so
        // we look for the canonical opening shape directly instead of
        // trying to bound on `]`.
        $modeOptionsAutoFirst = str_contains(
            $contractSource,
            "= [\n  { value: 'auto'",
        ) || preg_match(
            "/MODE_OPTIONS[\s\S]+?=\s*\[\s*\{\s*value:\s*'auto'/s",
            $contractSource,
        ) === 1;
        $defaultTaskAutoIsAuto = preg_match(
            "/function defaultTaskForMode[\s\S]+?if\s*\(mode\s*===\s*'auto'\)\s*return\s*'auto'/s",
            $contractSource,
        ) === 1;
        // `flowIdForMode('auto','auto')` resolves to 'auto' iff
        // UX_FLOW_MAP.auto.auto === 'auto'. We require ALL four auto-mode
        // tasks to resolve to the literal 'auto' string so no task variant
        // silently degrades to a programming flow.
        $flowIdAutoIsAuto = preg_match(
            "/auto:\s*\{\s*auto:\s*'auto',\s*direct:\s*'auto',\s*plan:\s*'auto',\s*review:\s*'auto',?\s*\}/s",
            $contractSource,
        ) === 1;
        $useAtlasAiStartsAuto = preg_match(
            "/useState<AtlasAiMode>\('auto'\)/",
            $useAtlasAiSource,
        ) === 1 && preg_match(
            "/useState<AtlasAiTask>\('auto'\)/",
            $useAtlasAiSource,
        ) === 1;

        $passed = $modeOptionsAutoFirst
            && $defaultTaskAutoIsAuto
            && $flowIdAutoIsAuto
            && $useAtlasAiStartsAuto;

        return $this->check('no_legacy_programming_dev_default', $passed, [
            'mode_options_auto_first' => $modeOptionsAutoFirst,
            'default_task_for_auto_is_auto' => $defaultTaskAutoIsAuto,
            'flow_id_for_auto_auto_is_auto' => $flowIdAutoIsAuto,
            'use_atlas_ai_starts_auto_auto' => $useAtlasAiStartsAuto,
            'contract_file' => self::DESKTOP_CONTRACT,
            'use_atlas_ai_file' => self::DESKTOP_USE_ATLAS_AI,
        ]);
    }

    /**
     * Returns true when the controller invokes `$needle` (hyperflow entry)
     * BEFORE `$after` (legacy router). Order matters: the canonical
     * decision must be produced before the legacy router runs, so the
     * Desktop sees the canonical envelope as the authoritative one.
     */
    private function controllerCallOrder(string $source, string $needle, string $after): bool
    {
        $needleAt = strpos($source, $needle);
        $afterAt = strpos($source, $after);
        if ($needleAt === false || $afterAt === false) {
            return false;
        }

        return $needleAt < $afterAt;
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'evidence' => $evidence,
        ];
    }

    private function source(string $path): string
    {
        return is_file($path) ? File::get($path) : '';
    }

    /**
     * Resolve a path that lives in the repo root (not inside the Laravel
     * `atlas-server/` directory). `base_path()` returns the Laravel project
     * root, so cross-package files like the Desktop surface have to be
     * resolved relative to the repo root one level up.
     */
    private function repoPath(string $relative): string
    {
        return rtrim(dirname(base_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relative, '/');
    }
}
