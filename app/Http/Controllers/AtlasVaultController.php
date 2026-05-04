<?php

namespace App\Http\Controllers;

use App\Http\Requests\AtlasVaultExportSemanticRequest;
use App\Http\Requests\AtlasVaultImportRequest;
use App\Http\Requests\AtlasVaultResolveRequest;
use App\Http\Requests\AtlasVaultSyncRequest;
use App\Services\Semantic\AtlasVaultManagedNoteService;
use App\Services\Semantic\AtlasVaultSyncService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

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

        try {
            $payload = $sync->importPath((string) $data['path'], $request->writeRequested());
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 409);
    }

    public function exportSemantic(AtlasVaultExportSemanticRequest $request, AtlasVaultSyncService $sync): JsonResponse
    {
        $data = $request->validated();

        try {
            $payload = $sync->exportSemanticNote((string) $data['semantic_note_id'], $request->writeRequested());
        } catch (ModelNotFoundException) {
            return response()->json(['ok' => false, 'error' => 'Semantic note not found.'], 404);
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 409);
    }

    public function sync(AtlasVaultSyncRequest $request, AtlasVaultSyncService $sync): JsonResponse
    {
        $data = $request->validated();

        try {
            $payload = $sync->sync($request->writeRequested(), (int) ($data['limit'] ?? 200));
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 409);
    }

    public function conflicts(Request $request, AtlasVaultSyncService $sync): JsonResponse
    {
        $limit = max(1, min(1000, (int) $request->integer('limit', 100)));

        try {
            return response()->json($sync->conflicts($limit, [
                'status' => $request->query('status'),
                'direction' => $request->query('direction'),
                'operation' => $request->query('operation'),
            ]));
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }
    }

    public function item(string $item, AtlasVaultSyncService $sync): JsonResponse
    {
        try {
            return response()->json($sync->item($item));
        } catch (ModelNotFoundException) {
            return response()->json(['ok' => false, 'error' => 'AtlasVault sync item not found.'], 404);
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }
    }

    public function resolve(string $item, AtlasVaultResolveRequest $request, AtlasVaultSyncService $sync): JsonResponse
    {
        $data = $request->validated();

        try {
            return response()->json($sync->resolve(
                $item,
                (string) $data['resolution'],
                isset($data['reason']) ? (string) $data['reason'] : null,
            ));
        } catch (ModelNotFoundException) {
            return response()->json(['ok' => false, 'error' => 'AtlasVault sync item not found.'], 404);
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }
    }
}
