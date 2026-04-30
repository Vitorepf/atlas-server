<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiStreamEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_id' => $this->trace_id,
            'job_id' => $this->ai_job_id,
            'attempt_id' => $this->ai_job_attempt_id,
            'sequence' => $this->sequence,
            'event_type' => $this->event_type,
            'channel' => $this->channel,
            'content' => $this->content,
            'metadata' => Metadata::forResponse($this->metadata),
            'occurred_at' => $this->occurred_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
