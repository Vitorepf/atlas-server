<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiWorkerEvent;

class AiWorkerLogger
{
    public function event(
        string $eventType,
        string $message,
        string $severity = 'info',
        ?string $provider = null,
        ?AiJob $job = null,
        ?AiJobAttempt $attempt = null,
        array $metadata = [],
        ?string $workerId = null,
    ): AiWorkerEvent {
        return AiWorkerEvent::query()->create([
            'worker_id' => $workerId ?: (string) config('atlas.ai.worker_id', 'atlas-worker'),
            'provider' => $provider,
            'ai_job_id' => $job?->id,
            'ai_job_attempt_id' => $attempt?->id,
            'event_type' => $eventType,
            'severity' => $severity,
            'message' => $message,
            'metadata' => $metadata,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
