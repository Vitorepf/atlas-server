<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'severity' => $this->severity,
            'summary' => $this->summary,
            'evidence' => Metadata::forResponse($this->evidence),
            'privacy' => Metadata::forResponse($this->privacy),
            'refs' => Metadata::forResponse($this->refs),
            'metadata' => Metadata::forResponse($this->metadata),
            'occurred_at' => $this->occurred_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
