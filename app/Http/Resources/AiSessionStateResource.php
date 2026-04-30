<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiSessionStateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
            'version' => $this->version,
            'active' => $this->active,
            'objective' => $this->objective,
            'current_phase' => $this->current_phase,
            'current_topic' => $this->current_topic,
            'user_position' => $this->user_position,
            'decisions' => Metadata::forResponse($this->decisions),
            'open_loops' => Metadata::forResponse($this->open_loops),
            'next_steps' => Metadata::forResponse($this->next_steps),
            'relevant_artifacts' => Metadata::forResponse($this->relevant_artifacts),
            'constraints' => Metadata::forResponse($this->constraints),
            'pending_steer' => $this->pending_steer,
            'provider_context' => Metadata::forResponse($this->provider_context),
            'quality_notes' => Metadata::forResponse($this->quality_notes),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
