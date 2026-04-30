<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'description' => $this->description,
            'color_light' => $this->color_light,
            'color_dark' => $this->color_dark,
            'default_sensitivity' => $this->default_sensitivity,
            'external_ai_policy' => $this->external_ai_policy,
            'active' => (bool) $this->active,
            'sort_order' => (int) $this->sort_order,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
