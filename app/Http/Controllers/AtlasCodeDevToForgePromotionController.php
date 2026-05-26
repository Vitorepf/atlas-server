<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\DevToForgePromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Atlas Code · Dev-to-Forge Promotion endpoints.
 *
 *   GET  /atlas-code/dev-to-forge/threads/{thread}/promotion-preview?workspace=…
 *        → preview payload (read-only)
 *
 *   POST /atlas-code/dev-to-forge/threads/{thread}/promote
 *        body: {promotion_target, workspace_slug?, overrides{title,objective,…}}
 *        → persists candidate, optionally creates Obra when target=forge_obra
 *
 *   GET  /atlas-code/dev-to-forge/candidates[?workspace=…]
 *   GET  /atlas-code/dev-to-forge/candidates/{candidate}
 *   POST /atlas-code/dev-to-forge/candidates/{candidate}/dismiss
 *
 * Read endpoints are honest about empty state; mutation endpoints validate
 * the promotion_target against the canonical vocabulary.
 */
final class AtlasCodeDevToForgePromotionController extends Controller
{
    public function __construct(private readonly DevToForgePromotionService $service) {}

    public function preview(Request $request, string $thread): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:120'],
        ]);
        try {
            $payload = $this->service->previewForThread($thread, $data['workspace'] ?? null);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json(['preview' => $payload]);
    }

    public function promote(Request $request, string $thread): JsonResponse
    {
        $data = $request->validate([
            'promotion_target' => ['required', 'string', 'in:quick_intervention,obra_candidate,forge_obra'],
            'workspace_slug' => ['nullable', 'string', 'max:120'],
            'overrides' => ['nullable', 'array'],
            'overrides.title' => ['nullable', 'string', 'max:240'],
            'overrides.objective' => ['nullable', 'string', 'max:1200'],
            'overrides.context_summary' => ['nullable', 'string', 'max:4000'],
            'overrides.known_files' => ['nullable', 'array'],
            'overrides.known_files.*' => ['string', 'max:320'],
            'overrides.risks' => ['nullable', 'array'],
            'overrides.risks.*' => ['string', 'max:480'],
            'overrides.open_questions' => ['nullable', 'array'],
            'overrides.open_questions.*' => ['string', 'max:480'],
            'overrides.suggested_success_criteria' => ['nullable', 'array'],
            'overrides.suggested_success_criteria.*' => ['string', 'max:480'],
            'overrides.suggested_next_step' => ['nullable', 'string', 'max:1200'],
        ]);
        try {
            $candidate = $this->service->promote(
                threadId: $thread,
                promotionTarget: (string) $data['promotion_target'],
                overrides: (array) ($data['overrides'] ?? []),
                workspaceSlug: $data['workspace_slug'] ?? null,
            );
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['candidate' => $candidate], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:120'],
        ]);
        $items = $this->service->listCandidates($data['workspace'] ?? null);

        return response()->json([
            'schema_version' => DevToForgePromotionService::SCHEMA_VERSION,
            'data' => $items,
            'meta' => [
                'total' => count($items),
                'workspace_filter' => $data['workspace'] ?? null,
            ],
        ]);
    }

    public function show(string $candidate): JsonResponse
    {
        $row = $this->service->findCandidate($candidate);
        if ($row === null) {
            return response()->json(['error' => 'dev_to_forge_candidate_not_found'], 404);
        }

        return response()->json(['candidate' => $row]);
    }

    public function dismiss(Request $request, string $candidate): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:600'],
        ]);
        try {
            $row = $this->service->dismiss($candidate, $data['reason'] ?? null);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json(['candidate' => $row]);
    }
}
