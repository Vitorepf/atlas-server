<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuildAtlasOpenBrainContextRequest;
use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\AtlasOpenBrainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AtlasOpenBrainController extends Controller
{
    public function contextPack(BuildAtlasOpenBrainContextRequest $request, AtlasOpenBrainService $brain): JsonResponse
    {
        return response()->json([
            'open_brain' => $brain->contextPack($request->validated(), 'api'),
        ]);
    }

    public function audits(Request $request): JsonResponse
    {
        if (! Schema::hasTable('atlas_open_brain_access_logs')) {
            return response()->json(['open_brain_audits' => []]);
        }

        $limit = max(1, min(100, (int) $request->query('limit', 50)));

        return response()->json([
            'open_brain_audits' => AtlasOpenBrainAccessLog::query()
                ->latest('accessed_at')
                ->limit($limit)
                ->get()
                ->map(fn (AtlasOpenBrainAccessLog $log): array => [
                    'id' => $log->id,
                    'surface' => $log->surface,
                    'requester' => $log->requester,
                    'action' => $log->action,
                    'status' => $log->status,
                    'workspace_hash' => $log->workspace_hash,
                    'workspace_label' => $log->workspace_label,
                    'context_pack_hash' => $log->context_pack_hash,
                    'context_refs_count' => $log->context_refs_count,
                    'memory_refs_count' => $log->memory_refs_count,
                    'provider_safe' => $log->provider_safe,
                    'query' => $log->query_json ?? [],
                    'result_summary' => $log->result_summary_json ?? [],
                    'accessed_at' => $log->accessed_at?->toJSON(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
