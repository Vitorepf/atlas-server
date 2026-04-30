<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexSemanticNoteRequest;
use App\Http\Resources\SemanticNoteResource;
use App\Models\SemanticNote;
use App\Services\Semantic\SemanticNoteIndexer;
use App\Services\Semantic\VaultFileStore;
use Illuminate\Http\JsonResponse;

class SemanticNoteController extends Controller
{
    public function index(IndexSemanticNoteRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 50);

        $query = SemanticNote::query()
            ->withCount(['sourceLinks', 'targetLinks'])
            ->whereNull('deleted_at')
            ->latest('updated_at')
            ->limit($limit);

        if (! empty($data['type'])) {
            $query->whereIn('type', $data['type']);
        }
        if (! empty($data['status'])) {
            $query->whereIn('status', $data['status']);
        }
        if (! empty($data['domain'])) {
            $query->whereRaw('domains @> ?::jsonb', [json_encode([$data['domain']])]);
        }
        if (! empty($data['trigger_signal'])) {
            $query->whereRaw('trigger_signals @> ?::jsonb', [json_encode([$data['trigger_signal']])]);
        }
        if (! empty($data['since'])) {
            $query->where('updated_at', '>', $data['since']);
        }

        return response()->json([
            'notes' => SemanticNoteResource::collection($query->get())->resolve(),
        ]);
    }

    public function show(SemanticNote $semanticNote, VaultFileStore $vault): JsonResponse
    {
        $semanticNote->load([
            'sourceLinks.targetNote',
            'targetLinks.sourceNote',
        ]);

        $content = null;
        if (! $semanticNote->trashed()) {
            $content = $vault->read($semanticNote->path);
        }

        return response()->json([
            'note' => (new SemanticNoteResource($semanticNote))->resolve(),
            'content' => $content,
        ]);
    }

    public function reindex(SemanticNoteIndexer $indexer, VaultFileStore $vault): JsonResponse
    {
        $created = $vault->ensureVaultStructure();
        $result = $indexer->indexAll(changedOnly: true);

        return response()->json([
            'vault_created' => $created->values()->all(),
            'index' => $result,
        ]);
    }
}
