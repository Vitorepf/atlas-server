<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DigitalCategoryMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_identifier' => $this->source_identifier,
            'source_name' => $this->source_name,
            'source_kind' => $this->source_kind,
            'category_class' => $this->category_class,
            'category_label' => $this->category_label,
            'intentionality' => $this->intentionality,
            'classified_by' => $this->classified_by,
            'confidence' => $this->confidence,
            'valid_from' => $this->valid_from?->toJSON(),
            'valid_until' => $this->valid_until?->toJSON(),
            'notes' => $this->notes,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
