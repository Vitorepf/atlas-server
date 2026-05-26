<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai\Programming;

use App\Http\Controllers\Controller;
use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevPlanProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Atlas Dev Plan-Visible read model (AP-700 / Patamar A2).
 *
 * Exposes the persisted `atlas.dev.plan_visible.v1` projection so the
 * Desktop surface can render the proposed plan BEFORE the operator
 * approves provider execution. Read-only. Polling-friendly: every
 * response carries an `ETag` so clients can perform conditional GET
 * (`If-None-Match`) and avoid re-fetching identical plans.
 *
 * Routes:
 *   GET /api/ai/programming/work-items/{workItem}/plan-visible
 *   GET /api/ai/programming/plan-visible
 *
 * Returns:
 *   200 with provider-safe `atlas.dev.plan_visible.v1` payload.
 *   304 when If-None-Match matches the current plan_hash.
 *   404 when no plan-visible has been persisted for the work item.
 */
final class AtlasDevPlanVisibleController extends Controller
{
    public function __construct(
        private readonly AtlasDevPlanProjectionService $projection,
    ) {}

    public function show(Request $request, AtlasProgrammingWorkItem $workItem): JsonResponse
    {
        $plan = $this->projection->loadPersisted($workItem);
        if ($plan === null) {
            return response()->json([
                'message' => 'No Plan-Visible has been persisted for this work item yet.',
                'code' => 'plan_visible_not_persisted',
                'work_item_id' => $workItem->getKey(),
            ], 404);
        }

        $hash = $plan->hash();
        $etag = '"'.$hash.'"';
        $ifNoneMatch = (string) $request->header('If-None-Match', '');
        if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
            return response()->json(null, Response::HTTP_NOT_MODIFIED, [
                'ETag' => $etag,
            ]);
        }

        return response()->json([
            'plan_visible' => $plan->toProviderSafeArray(),
            'hash' => $hash,
        ], 200, [
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=2',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        $workItems = AtlasProgrammingWorkItem::query()
            ->whereNotNull('plan_json')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $items = [];
        foreach ($workItems as $workItem) {
            $plan = $this->projection->loadPersisted($workItem);
            if ($plan === null) {
                continue;
            }
            $items[] = [
                'work_item_id' => $workItem->getKey(),
                'work_item_code' => $workItem->code ?? null,
                'plan_hash' => $plan->hash(),
                'plan_visible' => $plan->toProviderSafeArray(),
            ];
        }

        return response()->json([
            'schema' => 'atlas.dev.plan_visible.index.v1',
            'count' => count($items),
            'items' => $items,
        ]);
    }
}
