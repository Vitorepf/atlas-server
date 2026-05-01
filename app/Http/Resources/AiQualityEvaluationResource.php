<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiQualityEvaluationResource extends JsonResource
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
            'agent_slug' => $this->agent_slug,
            'evaluator_version' => $this->evaluator_version,
            'score' => $this->score,
            'status' => $this->status,
            'dimensions' => Metadata::forResponse($this->dimensions),
            'flags' => Metadata::listForResponse($this->flags),
            'suggested_actions' => Metadata::listForResponse($this->suggested_actions),
            'metadata' => Metadata::forResponse($this->metadata),
            'actions' => $this->whenLoaded('actions', fn () => AiQualityActionResource::collection($this->actions)->resolve()),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
