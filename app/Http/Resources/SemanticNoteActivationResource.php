<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemanticNoteActivationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'note_id' => $this->note_id,
            'note' => $this->whenLoaded('note', fn () => new SemanticNoteResource($this->note)),
            'activation_type' => $this->activation_type,
            'context_type' => $this->context_type,
            'context_payload' => Metadata::forResponse($this->context_payload),
            'prompt' => $this->prompt,
            'shown_at' => $this->shown_at?->toJSON(),
            'acted_at' => $this->acted_at?->toJSON(),
            'dismissed_at' => $this->dismissed_at?->toJSON(),
            'usefulness_score' => $this->usefulness_score,
            'feedback_action' => $this->feedback_action,
            'operator_feedback' => $this->operator_feedback,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
