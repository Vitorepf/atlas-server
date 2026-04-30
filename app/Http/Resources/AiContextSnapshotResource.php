<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiContextSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_id' => $this->trace_id,
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt_hash' => $this->prompt_hash,
            'context_pack' => Metadata::forResponse($this->context_pack),
            'messages_included' => Metadata::forResponse($this->messages_included),
            'compaction_id' => $this->compaction_id,
            'provider_handoff_id' => $this->provider_handoff_id,
            'token_estimate' => $this->token_estimate,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
