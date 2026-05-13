<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiMessage;
use App\Models\AiThread;
use Illuminate\Http\JsonResponse;

/**
 * Atlas Code · normalized thread+messages contract.
 *
 * Legacy /ai/threads/{thread} returns `{ thread: { messages: [...] } }` with
 * heavy AiThreadResource. The desktop bridge wants a flat camelCase shape:
 *
 *   GET /api/atlas-code/threads/{thread}
 *   → {
 *       id, title, status, lastTraceId,
 *       messages: [{ id, role, body, ts, occurredAt }, ...]
 *     }
 *
 * Read-only. Mutations stay on /ai/threads/*.
 */
final class AtlasCodeThreadController extends Controller
{
    public function show(AiThread $thread): JsonResponse
    {
        $messages = $thread->messages()->orderBy('position')->limit(500)->get();

        return response()->json([
            'id' => (string) $thread->getKey(),
            'title' => (string) ($thread->title ?? 'Sessão Atlas'),
            'status' => (string) ($thread->status ?? 'active'),
            'surface' => (string) ($thread->surface ?? 'app'),
            'lastTraceId' => $thread->last_trace_id ? (string) $thread->last_trace_id : null,
            'sourceId' => $thread->source_id ? (string) $thread->source_id : null,
            'messageCount' => (int) ($thread->message_count ?? $messages->count()),
            'createdAt' => $thread->created_at?->toJSON(),
            'lastMessageAt' => $thread->last_message_at?->toJSON(),
            'messages' => $messages->map(fn (AiMessage $m): array => [
                'id' => (string) $m->getKey(),
                'role' => $this->mapRole((string) $m->role),
                'body' => $this->renderBody($m->content),
                'occurredAt' => ($m->occurred_at ?? $m->created_at)?->toJSON(),
                'provider' => (string) ($m->provider ?? ''),
                'model' => (string) ($m->model ?? ''),
            ])->all(),
        ]);
    }

    private function mapRole(string $role): string
    {
        return match ($role) {
            'assistant' => 'atlas',
            'user' => 'user',
            default => 'system',
        };
    }

    private function renderBody(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            if (isset($content['text']) && is_string($content['text'])) {
                return $content['text'];
            }
            return (string) json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        return '';
    }
}
