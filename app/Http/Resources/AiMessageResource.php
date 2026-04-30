<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'thread_id' => $this->thread_id,
            'trace_id' => $this->trace_id,
            'position' => $this->position,
            'role' => $this->role,
            'status' => $this->status,
            'content' => $this->content,
            'provider' => $this->provider,
            'model' => $this->model,
            'agent_slug' => $this->agent_slug,
            'token_estimate' => $this->token_estimate,
            'occurred_at' => $this->occurred_at?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
