<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1

use App\Services\Ai\AtlasAobgWorkspaceOnboardingService;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use App\Services\AtlasCode\WorkspaceFolderIntelligenceService;
use App\Services\AtlasCode\WorkspaceIntelligenceAssemblyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Atlas Code · Project/Workspace endpoints.
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
 *
 *   GET /api/atlas-code/projects/workspaces           · list profiles
 *   GET /api/atlas-code/projects/workspaces/{slug}    · single profile
 *   POST /api/atlas-code/projects/workspaces          · upsert persisted profile
 *   PATCH /api/atlas-code/projects/workspaces/{slug}  · update persisted profile
 *   DELETE /api/atlas-code/projects/workspaces/{slug} · archive persisted profile
 *
 * Profiles são read-model. UI usa o `slug` ativo para escopar listas de
 * Obras, labels (Atlas · Code / Blackink · Code) e safety gates.
 */
final class AtlasCodeWorkspaceController extends Controller
{
    public function __construct(
        private readonly AtlasCodeWorkspaceProfileService $profiles,
        private readonly AtlasAobgWorkspaceOnboardingService $aobgWorkspaces,
        private readonly WorkspaceFolderIntelligenceService $folderIntelligence,
        private readonly WorkspaceIntelligenceAssemblyService $assembly,
    ) {}

    public function index(): JsonResponse
    {
        // Folder intelligence é anexada SOMENTE aqui (read-model HTTP da UI):
        // os fluxos de chat resolvem profiles via service e não pagam o custo.
        $list = array_map(
            fn (array $profile): array => $this->withFolderIntelligence($profile),
            $this->profiles->listProfiles(),
        );

        return response()->json([
            'schema_version' => AtlasCodeWorkspaceProfileService::SCHEMA_VERSION,
            'data' => $list,
            'meta' => [
                'total' => count($list),
                'default_slug' => $this->profiles->defaultSlug(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $profile = $this->profiles->findBySlug($slug);
        if ($profile === null) {
            return response()->json([
                'error' => 'project_workspace_not_found',
                'slug' => $slug,
            ], 404);
        }

        return response()->json([
            'workspace' => $this->withFolderIntelligence($profile),
        ]);
    }

    /**
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    private function withFolderIntelligence(array $profile): array
    {
        $profile['folder_intelligence'] = $this->folderIntelligence->inspect(
            (string) ($profile['workspace_path'] ?? ''),
        );

        return $profile;
    }

    public function store(Request $request): JsonResponse
    {
        return $this->upsert($request);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        return $this->upsert($request, $slug);
    }

    public function destroy(string $slug): JsonResponse
    {
        try {
            $profile = $this->profiles->archivePersistedProfile($slug);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'schema_version' => AtlasCodeWorkspaceProfileService::SCHEMA_VERSION,
            'workspace' => $profile,
            'meta' => [
                'persisted' => true,
                'archived' => true,
                'execution_allowed' => false,
                'execution_blocked_reason' => 'workspace_profile_archived',
            ],
        ]);
    }

    private function upsert(Request $request, ?string $slug = null): JsonResponse
    {
        $payload = $request->validate([
            'slug' => ['sometimes', 'string', 'max:120'],
            'name' => ['sometimes', 'string', 'max:200'],
            'kind' => ['sometimes', 'string', 'max:80'],
            'workspace_path' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'repo_root' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'production_status' => ['sometimes', 'string', 'max:80'],
            'stack_summary' => ['sometimes', 'nullable', 'string'],
            'commands' => ['sometimes', 'array'],
            'test_commands' => ['sometimes', 'array'],
            'build_commands' => ['sometimes', 'array'],
            'dev_server_command' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'critical_areas' => ['sometimes', 'array'],
            'docs_status' => ['sometimes', 'string', 'max:80'],
            'default_risk' => ['sometimes', 'string', 'max:40'],
            'deployment_notes' => ['sometimes', 'nullable', 'string'],
            'surfaces_enabled' => ['sometimes', 'array'],
            'source' => ['sometimes', 'string', 'max:80'],
            'status' => ['sometimes', 'string', 'max:40'],
        ]);

        if ($slug !== null) {
            $payload['slug'] = $slug;
        }

        try {
            $profile = $this->profiles->upsertPersistedProfile($payload);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        }

        // AP-818 F2.1 — gatilho on-link: pasta válida + flag ON → assembly
        // ENFILEIRADO (jamais inline na request). Roda antes da resposta ser
        // montada para o retrato já sair como assembly_pending. Fail-open: a
        // fila indisponível nunca quebra o save da ficha.
        $assemblyDispatch = ['queued' => false, 'reason' => 'auto_assemble_disabled'];
        if ((bool) config('atlas.code_folder_intelligence.auto_assemble', false)) {
            try {
                $assemblyDispatch = $this->assembly->queueAssembly(
                    (string) ($profile['workspace_path'] ?? ''),
                );
            } catch (Throwable $exception) {
                $assemblyDispatch = ['queued' => false, 'reason' => substr($exception->getMessage(), 0, 120)];
            }
        }

        // Ativação AOBG: medida em 30s+ para o umbrella Atlas — a classe de
        // trabalho que NÃO pode rodar inline (estourava max_execution_time e
        // matava o save da ficha). Com o assembly enfileirado, ela roda DENTRO
        // do job; inline só permanece no modo flag-OFF (status quo anterior).
        $aobgActivation = ($assemblyDispatch['queued'] ?? false)
            ? [
                'ok' => true,
                'action' => 'deferred_to_assembly_queue',
                'reason' => 'aobg_activation_runs_with_folder_intelligence_assembly',
            ]
            : $this->activateAobgWorkspaceIfConfigured($profile);

        return response()->json([
            'schema_version' => AtlasCodeWorkspaceProfileService::SCHEMA_VERSION,
            'workspace' => $this->withFolderIntelligence($profile),
            'meta' => [
                'persisted' => true,
                'execution_allowed' => (bool) data_get($profile, 'safety.execution_allowed', false),
                'execution_blocked_reason' => data_get($profile, 'safety.execution_blocked_reason'),
                'aobg_activation' => $aobgActivation,
                'folder_intelligence_assembly' => $assemblyDispatch,
            ],
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_workspace_controller'),
    ]);
    }

    /**
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    private function activateAobgWorkspaceIfConfigured(array $profile): array
    {
        if (! (bool) config('atlas.aobg.workspace_api_auto_activate', true)) {
            return [
                'ok' => true,
                'action' => 'skipped_by_config',
                'reason' => 'workspace_api_auto_activate_disabled',
            ];
        }

        $path = trim((string) ($profile['workspace_path'] ?? $profile['repo_root'] ?? ''));
        if ($path === '') {
            return [
                'ok' => true,
                'action' => 'skipped_missing_workspace_path',
                'reason' => 'workspace_path_empty',
            ];
        }
        if (! is_dir($path)) {
            return [
                'ok' => false,
                'action' => 'skipped_missing_workspace_path',
                'reason' => 'workspace_path_not_directory',
            ];
        }

        return $this->aobgWorkspaces->activate(['workspace' => $path]);
    }
}
