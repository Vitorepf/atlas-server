<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PassiveSignalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'source' => $this->source,
            'signal_type' => $this->signal_type,
            'value_numeric' => $this->value_numeric,
            'value_text' => $this->value_text,
            'unit' => $this->unit,
            'started_at' => $this->started_at?->toJSON(),
            'ended_at' => $this->ended_at?->toJSON(),
            'recorded_timezone' => $this->recorded_timezone,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
