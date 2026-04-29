<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DigitalSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'source' => $this->source,
            'source_event_id' => $this->source_event_id,
            'source_identifier' => $this->source_identifier,
            'source_name' => $this->source_name,
            'source_kind' => $this->source_kind,
            'category_class_at_time' => $this->category_class_at_time,
            'category_label_at_time' => $this->category_label_at_time,
            'intentionality' => $this->intentionality,
            'started_at' => $this->started_at?->toJSON(),
            'ended_at' => $this->ended_at?->toJSON(),
            'duration_seconds' => $this->duration_seconds,
            'recorded_timezone' => $this->recorded_timezone,
            'focus_mode_active' => $this->focus_mode_active,
            'project_name' => $this->project_name,
            'task_name' => $this->task_name,
            'url_domain' => $this->url_domain,
            'productivity_score' => $this->productivity_score,
            'linked_capture_id' => $this->linked_capture_id,
            'linked_decision_id' => $this->linked_decision_id,
            'raw_payload' => Metadata::forResponse($this->raw_payload),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
