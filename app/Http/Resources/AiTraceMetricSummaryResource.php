<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiTraceMetricSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_id' => $this->trace_id,
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
            'client_id' => $this->client_id,
            'surface' => $this->surface,
            'runtime' => $this->runtime,
            'provider' => $this->provider,
            'model' => $this->model,
            'agent_slug' => $this->agent_slug,
            'task_type' => $this->task_type,
            'status' => $this->status,
            'latency' => [
                'app_send_to_accept_ms' => $this->app_send_to_accept_ms,
                'app_send_to_visible_ms' => $this->app_send_to_visible_ms,
                'queue_wait_ms' => $this->queue_wait_ms,
                'first_token_ms' => $this->first_token_ms,
                'provider_latency_ms' => $this->provider_latency_ms,
                'total_latency_ms' => $this->total_latency_ms,
            ],
            'cost' => [
                'prompt_tokens' => $this->prompt_tokens,
                'completion_tokens' => $this->completion_tokens,
                'total_tokens' => $this->total_tokens,
                'estimated_tokens' => $this->estimated_tokens,
                'token_source' => $this->token_source,
                'cost_microusd' => $this->cost_microusd,
                'cost_confidence' => $this->cost_confidence,
                'cost_source' => $this->cost_source,
                'cost_mode' => $this->cost_mode,
            ],
            'context' => [
                'context_tokens' => $this->context_tokens,
                'context_refs_count' => $this->context_refs_count,
                'useful_context_refs_count' => $this->useful_context_refs_count,
                'compaction_used' => $this->compaction_used,
                'provider_handoff_used' => $this->provider_handoff_used,
                'context_efficiency_score' => $this->context_efficiency_score,
            ],
            'scores' => [
                'auto_quality_score' => $this->auto_quality_score,
                'continuity_score' => $this->continuity_score,
                'human_feedback_score' => $this->human_feedback_score,
                'outcome_score' => $this->outcome_score,
                'remediation_score' => $this->remediation_score,
                'final_quality_score' => $this->final_quality_score,
                'final_efficiency_score' => $this->final_efficiency_score,
            ],
            'signals' => [
                'backgrounded_during_run' => $this->backgrounded_during_run,
                'recovered_from_pending' => $this->recovered_from_pending,
                'first_pass_success' => $this->first_pass_success,
                'needed_remediation' => $this->needed_remediation,
                'remediation_count' => $this->remediation_count,
                'reask_detected' => $this->reask_detected,
                'provider_switched_after_response' => $this->provider_switched_after_response,
            ],
            'score_components' => Metadata::forResponse($this->score_components),
            'metadata' => Metadata::forResponse($this->metadata),
            'computed_at' => $this->computed_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
