<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiTelemetryEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_key' => $this->event_key,
            'correlation_id' => $this->correlation_id,
            'trace_id' => $this->trace_id,
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
            'ai_job_id' => $this->ai_job_id,
            'ai_job_attempt_id' => $this->ai_job_attempt_id,
            'client_id' => $this->client_id,
            'surface' => $this->surface,
            'runtime' => $this->runtime,
            'app_version' => $this->app_version,
            'cli_version' => $this->cli_version,
            'provider' => $this->provider,
            'model' => $this->model,
            'agent_slug' => $this->agent_slug,
            'event_name' => $this->event_name,
            'event_phase' => $this->event_phase,
            'occurred_at_client' => $this->occurred_at_client?->toJSON(),
            'received_at' => $this->received_at?->toJSON(),
            'duration_ms' => $this->duration_ms,
            'numeric_value' => $this->numeric_value,
            'unit' => $this->unit,
            'metadata' => Metadata::forResponse($this->metadata),
            'privacy' => Metadata::forResponse($this->privacy),
            'schema_version' => $this->schema_version,
            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
