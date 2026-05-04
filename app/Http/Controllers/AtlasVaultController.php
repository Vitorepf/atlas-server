<?php

namespace App\Http\Controllers;

use App\Http\Requests\AtlasVaultExportSemanticRequest;
use App\Http\Requests\AtlasVaultImportRequest;
use App\Http\Requests\AtlasVaultResolveRequest;
use App\Http\Requests\AtlasVaultSyncRequest;
use App\Services\Semantic\AtlasVaultManagedNoteService;
use App\Services\Semantic\AtlasVaultSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasVaultController extends Controller
{
    public function status(AtlasVaultManagedNoteService $notes, AtlasVaultSyncService $sync): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'vault' => [
                ...$notes->status(),
                'sync_queue' => $sync->queueSummary(),
            ],
        ]);
    }

    public function import(AtlasVaultImportRequest $request, AtlasVaultSyncService $sync): JsonResponse
    {
        $data = $request->validated();
        $payload = $sync->importPath((string) $data['path'], $request->writeRequested());

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 409);
    }

    public function exportSemantic(AtlasVaultExportSemanticRequest $request, AtlasVaultSyncService $sync): JsonResponse
    {
        $data = $request->validated();
        $payload = $sync->exportSemanticNote((string) $data['semantic_note_id'], $request->writeRequested());

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 409);
    }

    public function sync(AtlasVaultSyncRequest $request, AtlasVaultSyncService $sync): JsonResponse
    {
        $data = $request->validated();
        $payload = $sync->sync($request->writeRequested(), (int) ($data['limit'] ?? 200));

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 409);
    }

    public function conflicts(Request $request, AtlasVaultSyncService $sync): JsonResponse
    {
        $limit = max(1, min(1000, (int) $request->integer('limit', 100)));

        return response()->json($sync->conflicts($limit));
    }

    public function resolve(string $item, AtlasVaultResolveRequest $request, AtlasVaultSyncService $sync): JsonResponse
    {
        $data = $request->validated();

        return response()->json($sync->resolve($item, (string) $data['resolution']));
    }
}
