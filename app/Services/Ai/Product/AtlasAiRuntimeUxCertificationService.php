<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RuntimeReadiness\AtlasAiRuntimeReadinessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Atlas AI · Runtime UX Certification.
 *
 * Cert read-only que prova: a UX de Mobile e Desktop expõe o runtime
 * (readiness + mission + approvals + handoff) de forma LEVE, sem vazar
 * JSON cru. Pareada com `AtlasAiProductCertificationService` (que cobre
 * plumbing canônico do produto) — esta cert cobre a CAMADA DE UX:
 *
 *   - Endpoint `/atlas/ai/runtime-readiness` retorna `ux_bundle` canon.
 *   - Mobile e Desktop expõem `useRuntimeReadiness` + view-model com
 *     `activeMission`, `pendingApprovalsCount`, `latestHandoff`,
 *     `primaryBlocker`.
 *   - Desktop tem `AtlasAiRuntimeStatusPill` montado no header.
 *   - Tests cobrem: ready não polui composer, blocked aparece, mission
 *     aparece, pending approvals aparece, dev/forge handoff renderiza,
 *     nenhum JSON cru chega na superfície pública.
 *
 * NÃO invoca provider, NÃO roda rivals, NÃO destrava nada.
 */
class AtlasAiRuntimeUxCertificationService
{
    public const SCHEMA_VERSION = 'atlas.ai.runtime_ux_certification.v1';

    public const PATH_READINESS_SERVICE = 'app/Services/Ai/RuntimeReadiness/AtlasAiRuntimeReadinessService.php';

    public const PATH_READINESS_CONTROLLER = 'app/Http/Controllers/AtlasAiRuntimeReadinessController.php';

    public const PATH_DESKTOP_HOOK = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/useRuntimeReadiness.ts';

    public const PATH_DESKTOP_VIEW_MODEL = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/runtimeReadinessView.ts';

    public const PATH_DESKTOP_PILL = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiRuntimeStatusPill.tsx';

    public const PATH_DESKTOP_SURFACE = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx';

    public const PATH_DESKTOP_TYPES = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/types.ts';

    public const PATH_DESKTOP_HOOK_TEST = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/__tests__/useRuntimeReadiness.test.ts';

    public const PATH_DESKTOP_PILL_TEST = 'atlas-desktop/apps/desktop/src/surfaces/atlas-ai/__tests__/runtimeStatusPillContract.test.ts';

    public const PATH_MOBILE_VIEW_MODEL = 'atlas-app/lib/atlasAi/runtimeReadiness.ts';

    public const PATH_MOBILE_HOOK = 'atlas-app/lib/atlasAi/useRuntimeReadiness.ts';

    public const PATH_MOBILE_CLIENT = 'atlas-app/lib/atlasAi/runtimeReadinessClient.ts';

    public const PATH_MOBILE_CONTEXT_SHEET = 'atlas-app/components/sheets/atlas-ai/AtlasAiContextSheet.tsx';

    public const PATH_MOBILE_TEST = 'atlas-app/scripts/atlas-ai-runtime-readiness.test.ts';

    public function __construct(private readonly AtlasAiRuntimeReadinessService $readiness) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->backendUxBundleCheck(),
            $this->desktopViewModelExtendedCheck(),
            $this->desktopPillExistsAndWiredCheck(),
            $this->mobileViewModelExtendedCheck(),
            $this->mobileContextSheetWiredCheck(),
            $this->noRawJsonLeakedCheck(),
            $this->testsCoverUxBundleCheck(),
        ];

        $failed = array_values(array_filter(
            $checks,
            static fn (array $c): bool => ($c['status'] ?? null) !== 'passed',
        ));
        $criticalFailed = array_values(array_filter(
            $failed,
            static fn (array $c): bool => ($c['severity'] ?? 'critical') === 'critical',
        ));
        $warnFailed = array_values(array_filter(
            $failed,
            static fn (array $c): bool => ($c['severity'] ?? 'critical') === 'warn',
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
                static fn (array $c): string => (string) ($c['id'] ?? 'unknown'),
                $criticalFailed,
            ),
            'claims' => [
                'declares_benchmark' => false,
                'declares_superiority' => false,
                'invokes_provider' => false,
                'runs_rivals' => false,
                'scope' => 'runtime_ux_layer_only',
            ],
            'writes' => false,
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /* ---------------------------------------------------------------- */

    private function backendUxBundleCheck(): array
    {
        $source = $this->source($this->repoPath(self::PATH_READINESS_SERVICE));
        $hasUxBundleMethod = str_contains($source, 'public function uxBundle');
        $emitsBundleKey = str_contains($source, "\$payload['ux_bundle'] = \$this->uxBundle()")
            || str_contains($source, "'ux_bundle' => \$this->uxBundle()");
        $declaresUxBundleSchema = str_contains($source, 'atlas.ai.runtime_readiness.ux_bundle.v1');
        $hashIsClean = $this->callOrder($source, "\$payload['certification_hash']", "\$payload['ux_bundle']");

        // Runtime smoke: call the actual service and confirm shape.
        $bundle = $this->readiness->uxBundle();
        $bundleShape = isset($bundle['schema_version'])
            && array_key_exists('active_mission', $bundle)
            && array_key_exists('pending_approvals_count', $bundle)
            && array_key_exists('latest_handoff', $bundle);

        $passed = $hasUxBundleMethod && $emitsBundleKey && $declaresUxBundleSchema
            && $hashIsClean && $bundleShape;

        return $this->check('backend_ux_bundle', $passed, 'critical', [
            'service_has_ux_bundle_method' => $hasUxBundleMethod,
            'report_emits_ux_bundle_key' => $emitsBundleKey,
            'ux_bundle_schema_declared' => $declaresUxBundleSchema,
            'ux_bundle_outside_certification_hash' => $hashIsClean,
            'runtime_smoke_returns_canonical_shape' => $bundleShape,
            'service_path' => self::PATH_READINESS_SERVICE,
        ]);
    }

    private function desktopViewModelExtendedCheck(): array
    {
        $typesSrc = $this->source($this->repoPath(self::PATH_DESKTOP_TYPES));
        $viewSrc = $this->source($this->repoPath(self::PATH_DESKTOP_VIEW_MODEL));

        $typesHaveUxBundle = str_contains($typesSrc, 'ux_bundle')
            && str_contains($typesSrc, 'active_mission')
            && str_contains($typesSrc, 'pending_approvals_count')
            && str_contains($typesSrc, 'latest_handoff');
        $viewHasNewFields = str_contains($viewSrc, 'activeMission')
            && str_contains($viewSrc, 'pendingApprovalsCount')
            && str_contains($viewSrc, 'latestHandoff')
            && str_contains($viewSrc, 'primaryBlocker');
        $viewExposesPrimaryBlocker = str_contains($viewSrc, 'humanizeBlockerId');

        $passed = $typesHaveUxBundle && $viewHasNewFields && $viewExposesPrimaryBlocker;

        return $this->check('desktop_view_model_extended', $passed, 'critical', [
            'types_declare_ux_bundle' => $typesHaveUxBundle,
            'view_model_exposes_new_fields' => $viewHasNewFields,
            'view_model_humanises_blocker_id' => $viewExposesPrimaryBlocker,
            'types_path' => self::PATH_DESKTOP_TYPES,
            'view_path' => self::PATH_DESKTOP_VIEW_MODEL,
        ]);
    }

    private function desktopPillExistsAndWiredCheck(): array
    {
        $pillPath = $this->repoPath(self::PATH_DESKTOP_PILL);
        $surfacePath = $this->repoPath(self::PATH_DESKTOP_SURFACE);
        $pillExists = is_file($pillPath);

        $pillSrc = $this->source($pillPath);
        $surfaceSrc = $this->source($surfacePath);

        $pillConsumesView = str_contains($pillSrc, 'RuntimeReadinessView')
            && str_contains($pillSrc, 'primaryBlocker')
            && str_contains($pillSrc, 'activeMission')
            && str_contains($pillSrc, 'pendingApprovalsCount')
            && str_contains($pillSrc, 'latestHandoff');
        $pillRendersNoRawJson = ! str_contains($pillSrc, 'JSON.stringify')
            && ! str_contains($pillSrc, 'JSON.parse')
            && ! str_contains($pillSrc, '{readiness.raw}');
        $surfaceMountsPill = str_contains($surfaceSrc, 'AtlasAiRuntimeStatusPill')
            && str_contains($surfaceSrc, 'useRuntimeReadiness()');

        $passed = $pillExists && $pillConsumesView && $pillRendersNoRawJson && $surfaceMountsPill;

        return $this->check('desktop_pill_exists_and_wired', $passed, 'critical', [
            'pill_component_present' => $pillExists,
            'pill_consumes_view_model' => $pillConsumesView,
            'pill_does_not_leak_raw_json' => $pillRendersNoRawJson,
            'surface_mounts_pill_with_hook' => $surfaceMountsPill,
            'pill_path' => self::PATH_DESKTOP_PILL,
            'surface_path' => self::PATH_DESKTOP_SURFACE,
        ]);
    }

    private function mobileViewModelExtendedCheck(): array
    {
        $modelPath = $this->repoPath(self::PATH_MOBILE_VIEW_MODEL);
        $hookPath = $this->repoPath(self::PATH_MOBILE_HOOK);
        $clientPath = $this->repoPath(self::PATH_MOBILE_CLIENT);

        $modelSrc = $this->source($modelPath);
        $modelHasUxBundle = str_contains($modelSrc, 'ux_bundle')
            && str_contains($modelSrc, 'active_mission')
            && str_contains($modelSrc, 'pending_approvals_count')
            && str_contains($modelSrc, 'latest_handoff');
        $modelHasNewFields = str_contains($modelSrc, 'activeMission')
            && str_contains($modelSrc, 'pendingApprovalsCount')
            && str_contains($modelSrc, 'latestHandoff')
            && str_contains($modelSrc, 'primaryBlocker');

        $hookPresent = is_file($hookPath);
        $clientPresent = is_file($clientPath);

        $passed = $modelHasUxBundle && $modelHasNewFields && $hookPresent && $clientPresent;

        return $this->check('mobile_view_model_extended', $passed, 'critical', [
            'mobile_view_model_declares_ux_bundle' => $modelHasUxBundle,
            'mobile_view_model_exposes_new_fields' => $modelHasNewFields,
            'mobile_hook_present' => $hookPresent,
            'mobile_client_present' => $clientPresent,
            'mobile_view_model_path' => self::PATH_MOBILE_VIEW_MODEL,
            'mobile_hook_path' => self::PATH_MOBILE_HOOK,
        ]);
    }

    private function mobileContextSheetWiredCheck(): array
    {
        $sheetPath = $this->repoPath(self::PATH_MOBILE_CONTEXT_SHEET);
        $sheetPresent = is_file($sheetPath);
        $src = $this->source($sheetPath);
        $usesHook = str_contains($src, 'useRuntimeReadiness');
        $rendersStatusBlock = str_contains($src, 'runtimeReadiness') || str_contains($src, 'showRuntimeBlock');

        $passed = $sheetPresent && $usesHook && $rendersStatusBlock;

        return $this->check('mobile_context_sheet_wired', $passed, 'critical', [
            'mobile_context_sheet_present' => $sheetPresent,
            'mobile_context_sheet_consumes_hook' => $usesHook,
            'mobile_context_sheet_renders_runtime_block' => $rendersStatusBlock,
            'mobile_context_sheet_path' => self::PATH_MOBILE_CONTEXT_SHEET,
        ]);
    }

    private function noRawJsonLeakedCheck(): array
    {
        $pillSrc = $this->source($this->repoPath(self::PATH_DESKTOP_PILL));
        $viewSrc = $this->source($this->repoPath(self::PATH_DESKTOP_VIEW_MODEL));
        $modelSrc = $this->source($this->repoPath(self::PATH_MOBILE_VIEW_MODEL));

        // The view-model exposes typed derived fields. The pill renders
        // strings/booleans only. Both surfaces document this in the public
        // shape — `raw` exists for advanced consumers, but no UI surface
        // should call `JSON.stringify(...)` on the raw object.
        $pillAvoidsStringify = ! str_contains($pillSrc, 'JSON.stringify(readiness.raw')
            && ! str_contains($pillSrc, '{readiness.raw}');
        $desktopViewExposesTyped = str_contains($viewSrc, 'RuntimeReadinessView')
            && str_contains($viewSrc, 'criticalFailed: number');
        $mobileViewExposesTyped = str_contains($modelSrc, 'RuntimeReadinessView')
            && str_contains($modelSrc, 'criticalFailed: number');

        $passed = $pillAvoidsStringify && $desktopViewExposesTyped && $mobileViewExposesTyped;

        return $this->check('no_raw_json_leaked_to_ui', $passed, 'critical', [
            'desktop_pill_does_not_json_stringify_raw' => $pillAvoidsStringify,
            'desktop_view_exposes_typed_fields' => $desktopViewExposesTyped,
            'mobile_view_exposes_typed_fields' => $mobileViewExposesTyped,
        ]);
    }

    private function testsCoverUxBundleCheck(): array
    {
        $desktopHookTest = $this->source($this->repoPath(self::PATH_DESKTOP_HOOK_TEST));
        $desktopPillTest = $this->source($this->repoPath(self::PATH_DESKTOP_PILL_TEST));
        $mobileTest = $this->source($this->repoPath(self::PATH_MOBILE_TEST));

        $desktopCoversBundle = str_contains($desktopHookTest, 'ux_bundle')
            && str_contains($desktopHookTest, 'activeMission')
            && str_contains($desktopHookTest, 'pendingApprovalsCount')
            && str_contains($desktopHookTest, 'latestHandoff');
        $desktopCoversPill = str_contains($desktopPillTest, 'NÃO renderiza quando runtime unavailable')
            && str_contains($desktopPillTest, 'não pode conter snake_case')
            && str_contains($desktopPillTest, 'missão')
            && str_contains($desktopPillTest, 'pendingApprovalsCount');
        $mobileCoversBundle = str_contains($mobileTest, 'ux_bundle')
            && str_contains($mobileTest, 'activeMission')
            && str_contains($mobileTest, 'pendingApprovalsCount')
            && str_contains($mobileTest, 'primaryBlocker');

        $passed = $desktopCoversBundle && $desktopCoversPill && $mobileCoversBundle;

        return $this->check('tests_cover_ux_bundle', $passed, 'critical', [
            'desktop_hook_test_covers_bundle' => $desktopCoversBundle,
            'desktop_pill_test_covers_contract' => $desktopCoversPill,
            'mobile_test_covers_bundle' => $mobileCoversBundle,
            'desktop_hook_test_path' => self::PATH_DESKTOP_HOOK_TEST,
            'desktop_pill_test_path' => self::PATH_DESKTOP_PILL_TEST,
            'mobile_test_path' => self::PATH_MOBILE_TEST,
        ]);
    }

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

    private function callOrder(string $source, string $needle, string $after): bool
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
