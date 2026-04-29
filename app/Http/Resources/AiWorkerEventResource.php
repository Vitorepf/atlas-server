<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiWorkerEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'worker_id' => $this->worker_id,
            'provider' => $this->provider,
            'ai_job_id' => $this->ai_job_id,
            'ai_job_attempt_id' => $this->ai_job_attempt_id,
            'event_type' => $this->event_type,
            'severity' => $this->severity,
            'message' => $this->message,
            'metadata' => Metadata::forResponse($this->metadata),
            'occurred_at' => $this->occurred_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
