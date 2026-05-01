<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiOutcomeLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_id' => $this->trace_id,
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
            'outcome_type' => $this->outcome_type,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'value_score' => $this->value_score,
            'confidence' => $this->confidence,
            'source' => $this->source,
            'occurred_at' => $this->occurred_at?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
