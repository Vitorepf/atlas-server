<?php

namespace App\Http\Controllers;

use App\Services\Ai\Attachments\AiAttachmentIndexService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAttachmentSearchController extends Controller
{
    public function __invoke(Request $request, AiAttachmentIndexService $index): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:500'],
            'thread_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'between:1,20'],
        ]);

        $results = $index
            ->search((string) $data['query'], $data['thread_id'] ?? null, (int) ($data['limit'] ?? 8))
            ->map(fn ($entry): array => [
                'id' => $entry->id,
                'trace_id' => $entry->trace_id,
                'thread_id' => $entry->thread_id,
                'attachment_id' => $entry->attachment_id,
                'attachment_kind' => $entry->attachment_kind,
                'source_name' => $entry->source_name,
                'mime_type' => $entry->mime_type,
                'unit_type' => $entry->unit_type,
                'unit_number' => $entry->unit_number,
                'title' => $entry->title,
                'excerpt' => $entry->excerpt,
                'visual_caption' => $entry->visual_caption,
                'metadata' => $entry->metadata,
                'indexed_at' => $entry->indexed_at?->toJSON(),
                'score' => $entry->score ?? null,
            ])
            ->values()
            ->all();

        return response()->json(['results' => $results]);
    }
}
