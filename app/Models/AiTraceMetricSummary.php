<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTraceMetricSummary extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_id',
        'thread_id',
        'session_id',
        'client_id',
        'surface',
        'runtime',
        'provider',
        'model',
        'agent_slug',
        'task_type',
        'status',
        'app_send_to_accept_ms',
        'app_send_to_visible_ms',
        'queue_wait_ms',
        'first_token_ms',
        'provider_latency_ms',
        'total_latency_ms',
        'backgrounded_during_run',
        'recovered_from_pending',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_tokens',
        'token_source',
        'cost_microusd',
        'cost_confidence',
        'cost_source',
        'cost_mode',
        'context_tokens',
        'context_refs_count',
        'useful_context_refs_count',
        'compaction_used',
        'provider_handoff_used',
        'context_efficiency_score',
        'auto_quality_score',
        'continuity_score',
        'human_feedback_score',
        'outcome_score',
        'remediation_score',
        'final_quality_score',
        'final_efficiency_score',
        'first_pass_success',
        'needed_remediation',
        'remediation_count',
        'reask_detected',
        'provider_switched_after_response',
        'router_mode',
        'router_selected_provider',
        'router_fallback_provider',
        'router_was_overridden',
        'score_components',
        'metadata',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'app_send_to_accept_ms' => 'integer',
            'app_send_to_visible_ms' => 'integer',
            'queue_wait_ms' => 'integer',
            'first_token_ms' => 'integer',
            'provider_latency_ms' => 'integer',
            'total_latency_ms' => 'integer',
            'backgrounded_during_run' => 'boolean',
            'recovered_from_pending' => 'boolean',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'estimated_tokens' => 'integer',
            'cost_microusd' => 'integer',
            'context_tokens' => 'integer',
            'context_refs_count' => 'integer',
            'useful_context_refs_count' => 'integer',
            'compaction_used' => 'boolean',
            'provider_handoff_used' => 'boolean',
            'context_efficiency_score' => 'integer',
            'auto_quality_score' => 'integer',
            'continuity_score' => 'integer',
            'human_feedback_score' => 'integer',
            'outcome_score' => 'integer',
            'remediation_score' => 'integer',
            'final_quality_score' => 'integer',
            'final_efficiency_score' => 'integer',
            'first_pass_success' => 'boolean',
            'needed_remediation' => 'boolean',
            'remediation_count' => 'integer',
            'reask_detected' => 'boolean',
            'provider_switched_after_response' => 'boolean',
            'router_was_overridden' => 'boolean',
            'score_components' => 'array',
            'metadata' => 'array',
            'computed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }
}
