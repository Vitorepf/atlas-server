<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasCalendarBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'block_date' => $this->block_date?->toDateString(),
            'timezone' => $this->timezone,
            'title' => $this->title,
            'starts_at' => $this->starts_at?->toJSON(),
            'ends_at' => $this->ends_at?->toJSON(),
            'source' => $this->source,
            'source_ref' => $this->source_ref,
            'task_id' => $this->task_id,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
