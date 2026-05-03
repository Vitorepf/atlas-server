<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_id',
        'router_decision_id',
        'policy_version',
        'decision_mode',
        'route_mode',
        'task_type',
        'risk_level',
        'context_strategy',
        'execution_strategy',
        'selected_provider',
        'selected_model',
        'fallback_provider',
        'operator_requested_provider',
        'requested_provider',
        'was_overridden',
        'confidence_score',
        'signals',
        'candidates',
        'constraints',
        'metrics_snapshot',
        'task_profile',
        'execution_graph',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'trace_id' => 'string',
            'router_decision_id' => 'string',
            'was_overridden' => 'boolean',
            'confidence_score' => 'integer',
            'signals' => 'array',
            'candidates' => 'array',
            'constraints' => 'array',
            'metrics_snapshot' => 'array',
            'task_profile' => 'array',
            'execution_graph' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }

    public function routerDecision(): BelongsTo
    {
        return $this->belongsTo(AiRouterDecision::class, 'router_decision_id');
    }
}
