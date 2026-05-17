<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSpecialistFlowExecution extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_id',
        'router_decision_id',
        'runtime_schema_version',
        'execution_schema_version',
        'flow_id',
        'handler_id',
        'handler_version',
        'status',
        'runtime_receipt_id',
        'runtime_contract_hash',
        'delegation_status',
        'delegation_target_flow_id',
        'runtime_payload',
        'execution_payload',
        'receipt',
        'delegation',
        'audit_checks',
        'response_shape',
    ];

    protected function casts(): array
    {
        return [
            'trace_id' => 'string',
            'router_decision_id' => 'string',
            'runtime_payload' => 'array',
            'execution_payload' => 'array',
            'receipt' => 'array',
            'delegation' => 'array',
            'audit_checks' => 'array',
            'response_shape' => 'array',
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
