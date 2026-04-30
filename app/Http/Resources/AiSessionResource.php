<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'thread_id' => $this->thread_id,
            'status' => $this->status,
            'purpose' => $this->purpose,
            'provider_primary' => $this->provider_primary,
            'provider_last' => $this->provider_last,
            'started_at' => $this->started_at?->toJSON(),
            'ended_at' => $this->ended_at?->toJSON(),
            'message_count' => $this->message_count,
            'token_estimate' => $this->token_estimate,
            'metadata' => Metadata::forResponse($this->metadata),
            'active_state' => $this->whenLoaded('activeState', fn () => $this->activeState ? new AiSessionStateResource($this->activeState) : null),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
