<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1

use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

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
    public function __construct(private readonly AtlasCodeWorkspaceProfileService $profiles) {}

    public function index(): JsonResponse
    {
        $list = $this->profiles->listProfiles();

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
            'workspace' => $profile,
        ]);
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

        return response()->json([
            'schema_version' => AtlasCodeWorkspaceProfileService::SCHEMA_VERSION,
            'workspace' => $profile,
            'meta' => [
                'persisted' => true,
                'execution_allowed' => (bool) data_get($profile, 'safety.execution_allowed', false),
                'execution_blocked_reason' => data_get($profile, 'safety.execution_blocked_reason'),
            ],
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_workspace_controller'),
    ]);
    }
}
