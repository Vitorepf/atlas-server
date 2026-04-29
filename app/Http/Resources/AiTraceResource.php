<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiTraceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_key' => $this->trace_key,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'status' => $this->status,
            'operator_input' => $this->operator_input,
            'intent' => $this->intent,
            'agent_slug' => $this->agent_slug,
            'provider' => $this->provider,
            'model' => $this->model,
            'skill_versions' => Metadata::forResponse($this->skill_versions),
            'context_refs' => Metadata::forResponse($this->context_refs),
            'prompt_hash' => $this->prompt_hash,
            'response_hash' => $this->response_hash,
            'response_text' => $this->response_text,
            'latency_ms' => $this->latency_ms,
            'feedback_score' => $this->feedback_score,
            'feedback_action' => $this->feedback_action,
            'feedback_comment' => $this->feedback_comment,
            'completed_at' => $this->completed_at?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'job' => $this->whenLoaded('job', fn () => new AiJobResource($this->job)),
            'jobs' => $this->whenLoaded('jobs', fn () => AiJobResource::collection($this->jobs)->resolve()),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
