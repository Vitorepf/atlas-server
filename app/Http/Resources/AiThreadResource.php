<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiThreadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'status' => $this->status,
            'surface' => $this->surface,
            'workspace' => $this->workspace,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'last_trace_id' => $this->last_trace_id,
            'last_provider' => $this->last_provider,
            'message_count' => $this->message_count,
            'last_message_at' => $this->last_message_at?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'messages' => $this->whenLoaded('messages', fn () => AiMessageResource::collection($this->messages)->resolve()),
            'active_session' => $this->whenLoaded('activeSession', fn () => $this->activeSession ? new AiSessionResource($this->activeSession) : null),
            'active_state' => $this->whenLoaded('activeState', fn () => $this->activeState ? new AiSessionStateResource($this->activeState) : null),
            'latest_compaction' => $this->whenLoaded('latestCompaction', fn () => $this->latestCompaction ? new AiCompactionResource($this->latestCompaction) : null),
            'latest_provider_handoff' => $this->whenLoaded('latestProviderHandoff', fn () => $this->latestProviderHandoff ? new AiProviderHandoffResource($this->latestProviderHandoff) : null),
            'last_trace' => $this->whenLoaded('lastTrace', fn () => $this->lastTrace ? new AiTraceResource($this->lastTrace) : null),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
