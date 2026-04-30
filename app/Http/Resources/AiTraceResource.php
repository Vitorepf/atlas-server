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
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
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
            'stream_url' => url("/api/ai/interactions/{$this->id}/stream"),
            'feedback_score' => $this->feedback_score,
            'feedback_action' => $this->feedback_action,
            'feedback_comment' => $this->feedback_comment,
            'completed_at' => $this->completed_at?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'thread' => $this->whenLoaded('thread', fn () => $this->thread ? new AiThreadResource($this->thread) : null),
            'session' => $this->whenLoaded('session', fn () => $this->session ? new AiSessionResource($this->session) : null),
            'job' => $this->whenLoaded('job', fn () => new AiJobResource($this->job)),
            'jobs' => $this->whenLoaded('jobs', fn () => AiJobResource::collection($this->jobs)->resolve()),
            'stream_events' => $this->whenLoaded('streamEvents', fn () => AiStreamEventResource::collection($this->streamEvents)->resolve()),
            'quality_evaluation' => $this->whenLoaded('qualityEvaluation', fn () => $this->qualityEvaluation ? new AiQualityEvaluationResource($this->qualityEvaluation) : null),
            'quality_actions' => $this->whenLoaded('qualityActions', fn () => AiQualityActionResource::collection($this->qualityActions)->resolve()),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
