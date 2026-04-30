<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BehaviorLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'behavior_id' => $this->behavior_id,
            'behavior_client_id' => $this->behavior_client_id,
            'log_date' => $this->log_date?->toDateString(),
            'value' => $this->value,
            'numeric_value' => $this->numeric_value,
            'note' => $this->note,
            'occurred_at' => $this->occurred_at?->toJSON(),
            'occurred_timezone' => $this->occurred_timezone,
            'quantity_numeric' => $this->quantity_numeric,
            'quantity_unit' => $this->quantity_unit,
            'intensity' => $this->intensity,
            'context' => Metadata::forResponse($this->context),
            'recorded_at' => $this->recorded_at?->toJSON(),
            'recorded_timezone' => $this->recorded_timezone,
            'source' => $this->source,
            'source_capture_id' => $this->source_capture_id,
            'auto_marked' => $this->auto_marked,
            'confirmed_by_operator' => $this->confirmed_by_operator,
            'confidence' => $this->confidence,
            'inferred_by' => $this->inferred_by,
            'consent_snapshot_id' => $this->consent_snapshot_id,
            'reverted_at' => $this->reverted_at?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
