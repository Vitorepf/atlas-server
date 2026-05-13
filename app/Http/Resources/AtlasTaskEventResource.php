<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasTaskEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'event_type' => $this->event_type,
            'source' => $this->source,
            'payload' => Metadata::forResponse($this->payload),
            'safety' => $this->safetySummary(),
            'occurred_at' => $this->occurred_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetySummary(): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        return [
            'schema_version' => 'atlas.task_orchestration.event_safety.v1',
            'audit_trail_event' => true,
            'payload_api_only' => true,
            'provider_dispatch_allowed' => false,
            'runtime_execution_allowed' => false,
            'policy_mutation_allowed' => false,
            'raw_provider_output_exposed' => false,
            'has_event_sequence' => data_get($payload, 'event_sequence') !== null,
            'has_previous_event_hash' => data_get($payload, 'previous_event_hash') !== null,
            'has_event_hash' => data_get($payload, 'event_hash') !== null,
            'event_type' => $this->event_type,
            'source' => $this->source,
        ];
    }
}
