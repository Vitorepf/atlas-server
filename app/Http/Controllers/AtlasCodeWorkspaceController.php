<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Http\JsonResponse;

/**
 * Atlas Code · Project/Workspace endpoints.
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
 *
 *   GET /api/atlas-code/projects/workspaces           · list profiles
 *   GET /api/atlas-code/projects/workspaces/{slug}    · single profile
 *
 * Profiles são read-model. UI usa o `slug` ativo para escopar listas de
 * Obras, labels (Atlas · Code / Blackink · Code) e safety gates.
 */
final class AtlasCodeWorkspaceController extends Controller
{
    public function __construct(private readonly AtlasCodeWorkspaceProfileService $profiles)
    {
    }

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
}
