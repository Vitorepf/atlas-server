<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev;

use App\Http\Controllers\Controller;
use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexEntry;
use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /ai/interactions/atlas-dev/runs
 *
 * Thin index endpoint for Desktop/CLI resume. Receipts remain the source of
 * truth; this endpoint only returns the provider-safe DB mirror keyed by
 * workspace_hash or thread_id so surfaces never scan the receipts filesystem.
 */
final class IndexController extends Controller
{
    public function __construct(
        private readonly AtlasDevRunIndexRepository $runIndex,
        private readonly ConfigRepository $config,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->config->get('atlas_dev.efficient.plan_enabled', false)) {
            return response()->json([
                'error' => [
                    'code' => 'ATLAS_DEV_PLAN_DISABLED',
                    'message' => 'Atlas Dev plan endpoint is disabled by feature flag.',
                ],
            ], 503);
        }

        $workspaceHash = $this->stringQuery($request, 'workspace_hash');
        $threadId = $this->stringQuery($request, 'thread_id');
        $limit = $this->limit($request);

        if ($workspaceHash === null && $threadId === null) {
            return response()->json([
                'error' => [
                    'code' => 'ATLAS_DEV_RUN_INDEX_FILTER_REQUIRED',
                    'message' => 'workspace_hash or thread_id query parameter is required.',
                ],
            ], 422);
        }

        $entries = $workspaceHash !== null
            ? $this->runIndex->listByWorkspace($workspaceHash, $limit)
            : $this->runIndex->listByThread((string) $threadId, $limit);

        if ($workspaceHash !== null && $threadId !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn (AtlasDevRunIndexEntry $entry): bool => $entry->threadId === $threadId,
            ));
        }

        return response()->json([
            'data' => [
                'items' => array_map(
                    static fn (AtlasDevRunIndexEntry $entry): array => $entry->toArray(),
                    $entries,
                ),
                'limit' => $limit,
                'workspace_hash' => $workspaceHash,
                'thread_id' => $threadId,
            ],
        ], 200);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function limit(Request $request): int
    {
        $default = max(1, (int) $this->config->get('atlas_dev.run_index.list_default_limit', 50));
        $max = max(1, (int) $this->config->get('atlas_dev.run_index.list_max_limit', 200));
        $raw = $request->query('limit');
        if (! is_numeric($raw)) {
            return min($default, $max);
        }

        return min(max(1, (int) $raw), $max);
    }
}
