<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiCompactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
            'reason' => $this->reason,
            'source_position_start' => $this->source_position_start,
            'source_position_end' => $this->source_position_end,
            'source_message_count' => $this->source_message_count,
            'summary' => $this->summary,
            'structured_state' => Metadata::forResponse($this->structured_state),
            'token_estimate_before' => $this->token_estimate_before,
            'token_estimate_after' => $this->token_estimate_after,
            'quality_gate_status' => $this->quality_gate_status,
            'provider' => $this->provider,
            'model' => $this->model,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
