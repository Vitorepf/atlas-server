<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiInboxItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'type' => $this->type,
            'category' => $this->category,
            'severity' => $this->severity,
            'status' => $this->status,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->body,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'initiator' => $this->initiator,
            'context_bundle_id' => $this->context_bundle_id,
            'dedupe_key' => $this->dedupe_key,
            'available_actions' => $this->available_actions ?? [],
            'response' => $this->response,
            'payload' => $this->payload ?? [],
            'deep_link' => $this->deep_link,
            'push_policy' => $this->push_policy ?? [],
            'priority_score' => $this->priority_score,
            'confidence_score' => $this->confidence_score,
            'expires_at' => $this->expires_at?->toJSON(),
            'snoozed_until' => $this->snoozed_until?->toJSON(),
            'read_at' => $this->read_at?->toJSON(),
            'resolved_at' => $this->resolved_at?->toJSON(),
            'dismissed_at' => $this->dismissed_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'context_bundle' => $this->whenLoaded('contextBundle', fn () => $this->contextBundle ? [
                'id' => $this->contextBundle->id,
                'purpose' => $this->contextBundle->purpose,
                'title' => $this->contextBundle->title,
                'summary' => $this->contextBundle->summary,
                'source_refs' => $this->contextBundle->source_refs ?? [],
                'trace_refs' => $this->contextBundle->trace_refs ?? [],
                'job_refs' => $this->contextBundle->job_refs ?? [],
                'metric_refs' => $this->contextBundle->metric_refs ?? [],
                'file_refs' => $this->contextBundle->file_refs ?? [],
                'diff_refs' => $this->contextBundle->diff_refs ?? [],
            ] : null),
        ];
    }
}
