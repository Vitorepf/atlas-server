<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasRoutineEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'routine_id' => $this->routine_id,
            'event_type' => $this->event_type,
            'source' => $this->source,
            'payload' => Metadata::forResponse($this->payload),
            'occurred_at' => $this->occurred_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
