<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiQualityActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluation_id' => $this->evaluation_id,
            'trace_id' => $this->trace_id,
            'remediation_trace_id' => $this->remediation_trace_id,
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
            'action_type' => $this->action_type,
            'status' => $this->status,
            'priority' => $this->priority,
            'reason' => $this->reason,
            'flags' => Metadata::forResponse($this->flags),
            'payload' => Metadata::forResponse($this->payload),
            'result' => Metadata::forResponse($this->result),
            'error_message' => $this->error_message,
            'dedupe_key' => $this->dedupe_key,
            'completed_at' => $this->completed_at?->toJSON(),
            'remediation_trace' => $this->whenLoaded('remediationTrace', fn () => $this->remediationTrace ? new AiTraceResource($this->remediationTrace) : null),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
