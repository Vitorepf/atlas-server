<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiThread;
use App\Services\Ai\Programming\AtlasDevToForgePromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Dev-to-Forge Promotion endpoints.
 *
 *   GET  /atlas-code/promotion/preview/{thread}        → read-only preview
 *   POST /atlas-code/promotion/{thread}/promote        → create candidate
 *
 * Read-only on preview. The POST validates that `promotion_target` is one of
 * the suggested allowed_targets and only then creates a Quick Intervention
 * record (thread metadata) or an Obra Candidate / Forge Obra (AtlasProject
 * with origin=atlas-dev-promotion).
 *
 * NEVER touches Forge runtime or provider invocation.
 */
final class AtlasDevToForgePromotionController extends Controller
{
    public function preview(
        AiThread $thread,
        AtlasDevToForgePromotionService $service,
    ): JsonResponse {
        return response()->json($service->preview($thread), 200);
    }

    public function promote(
        Request $request,
        AiThread $thread,
        AtlasDevToForgePromotionService $service,
    ): JsonResponse {
        $data = $request->validate([
            'promotion_target' => ['required', 'string', 'in:'.implode(',', [
                AtlasDevToForgePromotionService::TARGET_QUICK_INTERVENTION,
                AtlasDevToForgePromotionService::TARGET_OBRA_CANDIDATE,
                AtlasDevToForgePromotionService::TARGET_FORGE_OBRA,
            ])],
            'title' => ['nullable', 'string', 'max:180'],
            'objective' => ['nullable', 'string', 'max:600'],
            'context_summary' => ['nullable', 'string', 'max:4000'],
            'workspace_slug' => ['nullable', 'string', 'max:120'],
            'decided_by' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:600'],
        ]);

        try {
            $result = $service->promote(
                thread: $thread,
                requestedTarget: (string) $data['promotion_target'],
                overrides: [
                    'title' => $data['title'] ?? null,
                    'objective' => $data['objective'] ?? null,
                    'context_summary' => $data['context_summary'] ?? null,
                    'workspace_slug' => $data['workspace_slug'] ?? null,
                    'decided_by' => $data['decided_by'] ?? null,
                    'reason' => $data['reason'] ?? null,
                ],
            );
        } catch (\InvalidArgumentException $e) {
            $status = $e->getMessage() === 'workspace_required_for_promotion' ? 422 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json($result, 201);
    }
}
