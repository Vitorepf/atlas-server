<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai\SoftwareCompanyStewardship;

use App\Http\Controllers\Controller;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalInboxReadModelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product Mode operational inbox HTTP surface (AP-754/AP-765/AP-777 aggregate).
 *
 * Fast, read-only projection for Desktop/Mobile operational inbox. Always returns
 * within a defensive time budget with explicit empty/degraded states — never an
 * infinite loading shell.
 */
final class ProductModeOperationalInboxController extends Controller
{
    private const MAX_BUDGET_MS = 2000;

    public function __construct(
        private readonly ProductModeOperationalInboxReadModelService $readModel,
    ) {}

    public function show(Request $request, string $portfolio): JsonResponse
    {
        $input = [];
        foreach (['area', 'area_id', 'repo_root'] as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                $input[$key] = (string) $value;
            }
        }

        foreach (['enabled', 'continuous_runner_enabled', 'kill_switch', 'global_kill_switch', 'area_kill_switch'] as $flag) {
            if ($request->has($flag)) {
                $input[$flag] = filter_var($request->query($flag), FILTER_VALIDATE_BOOLEAN);
            }
        }

        $budgetMs = min(self::MAX_BUDGET_MS, max(0, (int) $request->query('budget_ms', ProductModeOperationalInboxReadModelService::DEFAULT_BUDGET_MS)));
        $input['budget_ms'] = $budgetMs;

        $areaId = (string) ($input['area_id'] ?? $input['area'] ?? ProductModeOperationalInboxReadModelService::DEFAULT_AREA_ID);
        $body = $this->readModel->project($areaId, $portfolio, $input);

        $hash = (string) ($body['projection_hash'] ?? hash('sha256', json_encode($body) ?: ''));
        $etag = '"'.$hash.'"';
        if ((string) $request->header('If-None-Match', '') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        return response()->json($body, 200, [
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=3, stale-while-revalidate=10',
            'X-Atlas-Operational-Inbox-Budget-Ms' => (string) $budgetMs,
        ]);
    }
}
