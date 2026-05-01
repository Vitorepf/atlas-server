<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CognitiveGameRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'game_key' => $this->game_key,
            'title' => $this->title,
            'input_note_ids' => Metadata::listForResponse($this->input_note_ids),
            'prompt' => $this->prompt,
            'operator_answer' => $this->operator_answer,
            'atlas_feedback' => $this->atlas_feedback,
            'score' => $this->score,
            'duration_seconds' => $this->duration_seconds,
            'promoted_note_id' => $this->promoted_note_id,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
