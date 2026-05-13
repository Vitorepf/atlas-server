<?php

namespace App\Http\Controllers;

use App\Models\AiThread;
use App\Models\AtlasProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Atlas Code · sessions for an obra.
 *
 * Wraps AiThread filtered by the active project (workspace = project_id),
 * shaped to the contract consumed by atlas-desktop's bridge:
 *   GET /api/atlas-code/works/{work}/sessions
 *
 * The Kernel still owns AiThread; this controller only projects.
 */
class AtlasCodeSessionController extends Controller
{
    public function indexForWork(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $statuses = $this->parseStatusFilter($data['status'] ?? null);
        $limit = (int) ($data['limit'] ?? 50);

        $query = AiThread::query()
            ->where(function ($q) use ($project) {
                $q->where('source_id', $project->getKey())
                    ->orWhere('workspace', $project->getKey())
                    ->orWhere('source_id', (string) $project->getKey());
            })
            ->orderByDesc('last_message_at')
            ->limit($limit);

        if (! empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        $threads = $query->get();
        $activeCount = $threads->whereIn('status', ['running', 'active', 'streaming'])->count();

        return response()->json([
            'data' => $threads->map(fn (AiThread $thread): array => $this->shape($thread, $project))->all(),
            'meta' => [
                'total' => $threads->count(),
                'active' => $activeCount,
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function parseStatusFilter(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $s): string => trim($s),
            explode(',', $raw)
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(AiThread $thread, AtlasProject $project): array
    {
        $createdMs = $thread->created_at?->valueOf() ?? 0;
        $touchedMs = ($thread->last_message_at ?? $thread->updated_at)?->valueOf() ?? 0;
        $duration = max(0, $touchedMs - $createdMs);

        return [
            'id' => (string) $thread->getKey(),
            'obraId' => (string) $project->getKey(),
            'threadId' => (string) $thread->getKey(),
            'title' => (string) ($thread->title ?? $thread->summary ?? "Thread {$thread->getKey()}"),
            'status' => $this->normaliseStatus((string) ($thread->status ?? 'idle')),
            'turns' => (int) ($thread->message_count ?? 0),
            'durationMs' => $duration,
            'origin' => $this->guessOrigin($thread),
            'snapshot' => $this->extractSnapshot($thread),
        ];
    }

    private function normaliseStatus(string $status): string
    {
        return match ($status) {
            'running', 'active', 'streaming' => 'running',
            'paused', 'awaiting_input' => 'paused',
            'completed', 'done' => 'done',
            'failed', 'error' => 'failed',
            default => 'paused',
        };
    }

    private function guessOrigin(AiThread $thread): string
    {
        $surface = (string) ($thread->surface ?? '');
        return match (true) {
            str_contains($surface, 'voice') => 'voice',
            str_contains($surface, 'cli') => 'cli',
            default => 'manual',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractSnapshot(AiThread $thread): ?array
    {
        $meta = $thread->metadata ?? [];
        if (! isset($meta['snapshot'])) {
            return null;
        }

        return [
            'sizeKb' => (float) ($meta['snapshot']['size_kb'] ?? 0),
            'intentPreserved' => (bool) ($meta['snapshot']['intent_preserved'] ?? true),
            'evidenceRefs' => (int) ($meta['snapshot']['evidence_refs'] ?? 0),
        ];
    }
}
