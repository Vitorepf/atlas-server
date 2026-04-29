<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcrastinationEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'detected_at' => $this->detected_at?->toJSON(),
            'detected_timezone' => $this->detected_timezone,
            'duration_min' => $this->duration_min,
            'primary_category_class' => $this->primary_category_class,
            'primary_category_label' => $this->primary_category_label,
            'mission_active' => $this->mission_active,
            'mission_context' => Metadata::forResponse($this->mission_context),
            'physiological_state' => Metadata::forResponse($this->physiological_state),
            'subjective_state' => Metadata::forResponse($this->subjective_state),
            'digital_context' => Metadata::forResponse($this->digital_context),
            'rule_version' => $this->rule_version,
            'confidence' => $this->confidence,
            'confronted' => $this->confronted,
            'operator_response' => $this->operator_response,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
