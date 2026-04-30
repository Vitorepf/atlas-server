<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VaultHealthSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'snapshot_date' => $this->snapshot_date?->toDateString(),
            'total_notes' => $this->total_notes,
            'active_notes' => $this->active_notes,
            'inbox_notes' => $this->inbox_notes,
            'invalid_notes' => $this->invalid_notes,
            'stale_notes' => $this->stale_notes,
            'notes_without_triggers' => $this->notes_without_triggers,
            'notes_without_links' => $this->notes_without_links,
            'activations_7d' => $this->activations_7d,
            'useful_activations_7d' => $this->useful_activations_7d,
            'health_state' => $this->health_state,
            'recommendations' => Metadata::forResponse($this->recommendations),
            'cognitive_return' => data_get(Metadata::forResponse($this->metadata), 'cognitive_return', []),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
