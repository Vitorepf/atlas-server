<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_id' => $this->trace_id,
            'client_id' => $this->client_id,
            'kind' => $this->kind,
            'status' => $this->status,
            'priority' => $this->priority,
            'agent_slug' => $this->agent_slug,
            'provider' => $this->provider,
            'model' => $this->model,
            'input_text' => $this->input_text,
            'context_refs' => Metadata::forResponse($this->context_refs),
            'payload' => Metadata::forResponse($this->payload),
            'result_text' => $this->result_text,
            'result_json' => Metadata::forResponse($this->result_json),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'available_at' => $this->available_at?->toJSON(),
            'reserved_at' => $this->reserved_at?->toJSON(),
            'started_at' => $this->started_at?->toJSON(),
            'finished_at' => $this->finished_at?->toJSON(),
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'timeout_seconds' => $this->timeout_seconds,
            'worker_id' => $this->worker_id,
            'metadata' => Metadata::forResponse($this->metadata),
            'trace' => $this->whenLoaded('trace', fn () => new AiTraceResource($this->trace)),
            'attempt_history' => $this->whenLoaded('attemptHistory', fn () => AiJobAttemptResource::collection($this->attemptHistory)->resolve()),
            'stream_events' => $this->whenLoaded('streamEvents', fn () => AiStreamEventResource::collection($this->streamEvents)->resolve()),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
