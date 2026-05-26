<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiStreamEvent;
use App\Models\AiTrace;
use App\Services\Ai\AtlasForge\AtlasObraDeterministicReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Obra Replay HTTP read model (AP-705 wiring).
 *
 * Reads the canonical event sequence for an `AiTrace` from `ai_stream_events`,
 * pipes it through `AtlasObraDeterministicReplayService` and returns the
 * canonical `atlas.obra.replay.v1` snapshot. Optional `?decision=<id>`
 * returns the lineage chain for a specific decision.
 *
 * Read-only. ETag based on the replay_hash.
 */
final class AtlasObraReplayController extends Controller
{
    public function __construct(
        private readonly AtlasObraDeterministicReplayService $replay,
    ) {}

    public function show(Request $request, AiTrace $trace): JsonResponse
    {
        if (! Schema::hasTable('ai_stream_events')) {
            return response()->json([
                'message' => 'ai_stream_events table is not available in this environment.',
                'code' => 'stream_events_unavailable',
            ], 503);
        }

        $events = AiStreamEvent::query()
            ->where('trace_id', $trace->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->limit(2000)
            ->get();

        $shaped = $events->map(static function (AiStreamEvent $event): array {
            $metadata = is_array($event->metadata ?? null) ? $event->metadata : [];

            // Best-effort projection: AiStreamEvent's metadata may already
            // carry a `phase_out` / `decision_id` if the producer is a
            // canonical AAEOS phase handoff. Otherwise we synthesize a
            // minimal envelope so replay still tracks event ordering.
            return [
                'phase_out' => (string) ($metadata['phase_out'] ?? $event->event_type ?? ''),
                'decision_id' => (string) ($metadata['decision_id'] ?? ''),
                'receipt_id' => (string) ($metadata['receipt_id'] ?? ''),
                'blockers' => (array) ($metadata['blockers'] ?? []),
                'sequence' => (int) $event->sequence,
                'occurred_at' => $event->occurred_at?->toJSON(),
            ];
        })->toArray();

        $decision = trim((string) $request->query('decision', ''));
        $body = $decision !== ''
            ? [
                'schema' => 'atlas.obra.replay.lineage.v1',
                'trace_id' => $trace->id,
                'decision_id' => $decision,
                'lineage' => $this->replay->lineage($shaped, $decision),
            ]
            : array_merge(
                ['trace_id' => $trace->id],
                $this->replay->replay($shaped),
            );

        $hash = (string) ($body['replay_hash'] ?? hash('sha256', json_encode($body) ?: ''));
        $etag = '"'.$hash.'"';
        if ((string) $request->header('If-None-Match', '') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        return response()->json($body, 200, ['ETag' => $etag, 'Cache-Control' => 'private, max-age=5']);
    }
}
