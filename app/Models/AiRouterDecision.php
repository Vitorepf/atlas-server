<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiRouterDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_id',
        'schema_version',
        'surface_id',
        'flow_id',
        'flow_origin',
        'command_intent',
        'routing_reason',
        'routing_confidence',
        'workspace_present',
        'mode',
        'selected_provider',
        'fallback_provider',
        'signals',
        'handoff_payload',
        'alternative_flow_ids',
        'reason',
        'was_overridden',
    ];

    protected function casts(): array
    {
        return [
            'trace_id' => 'string',
            'signals' => 'array',
            'handoff_payload' => 'array',
            'alternative_flow_ids' => 'array',
            'workspace_present' => 'boolean',
            'was_overridden' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }

    public function specialistFlowExecution(): HasOne
    {
        return $this->hasOne(AiSpecialistFlowExecution::class, 'router_decision_id');
    }
}
