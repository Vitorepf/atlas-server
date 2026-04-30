<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemanticNoteLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_note_id' => $this->source_note_id,
            'target_note_id' => $this->target_note_id,
            'source_note' => $this->whenLoaded('sourceNote', fn () => new SemanticNoteResource($this->sourceNote)),
            'target_note' => $this->whenLoaded('targetNote', fn () => new SemanticNoteResource($this->targetNote)),
            'link_type' => $this->link_type,
            'explanation' => $this->explanation,
            'created_by' => $this->created_by,
            'confidence' => $this->confidence,
            'confirmed_by_operator' => $this->confirmed_by_operator,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
