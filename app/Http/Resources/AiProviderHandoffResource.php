<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiProviderHandoffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
            'from_provider' => $this->from_provider,
            'to_provider' => $this->to_provider,
            'reason' => $this->reason,
            'brief_text' => $this->brief_text,
            'brief_json' => Metadata::forResponse($this->brief_json),
            'compaction_id' => $this->compaction_id,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
