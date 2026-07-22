<?php

namespace App\Services\Ai\Streaming;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiStreamEvent;
use App\Services\Ai\Mobile\AtlasLiveActivityPushService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiStreamRecorder
{
    public function record(
        AiJob $job,
        ?AiJobAttempt $attempt,
        string $eventType,
        string $content = '',
        array $metadata = [],
        ?string $channel = null,
    ): ?AiStreamEvent {
        if (! DatabaseTableAvailability::has('ai_stream_events')) {
            return null;
        }

        $eventType = in_array($eventType, ['lifecycle', 'permission', 'progress', 'stdout', 'stderr', 'token', 'response', 'error', 'tool', 'thinking'], true)
            ? $eventType
            : 'progress';

        $event = DB::transaction(function () use ($job, $attempt, $eventType, $content, $metadata, $channel): AiStreamEvent {
            if ($job->trace_id) {
                DB::table('ai_traces')
                    ->where('id', $job->trace_id)
                    ->lockForUpdate()
                    ->value('id');
            }

            $lastSequence = (int) AiStreamEvent::query()
                ->where('trace_id', $job->trace_id)
                ->max('sequence');

            return AiStreamEvent::query()->create([
                'trace_id' => $job->trace_id,
                'ai_job_id' => $job->id,
                'ai_job_attempt_id' => $attempt?->id,
                'sequence' => $lastSequence + 1,
                'event_type' => $eventType,
                'channel' => $channel,
                'content' => Str::limit($content, 16000, ''),
                'metadata' => $metadata,
                'occurred_at' => now(),
            ]);
        });

        // O stream gravado é a única fonte da Live Activity. A projeção APNs
        // roda depois do commit e falha aberta: jamais pode comprometer o
        // ledger canônico ou o processo do agente.
        $presence = app(AtlasLiveActivityPushService::class);
        $presence->startFor($event);
        $presence->publish($event);

        return $event;
    }

    public function recordProviderEvent(AiJob $job, ?AiJobAttempt $attempt, array $event): ?AiStreamEvent
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : 'progress';
        $content = is_string($event['content'] ?? null) ? $event['content'] : '';
        $channel = is_string($event['channel'] ?? null) ? $event['channel'] : null;
        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        if (is_string($event['name'] ?? null)) {
            $metadata = ['name' => $event['name']] + $metadata;
        }

        return $this->record($job, $attempt, $type, $content, $metadata, $channel);
    }
}
