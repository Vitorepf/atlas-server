<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Programming Runtime telemetry event. Internal measurement only — never a
 * substitute for benchmark evidence. The aggregator always reports
 * `benchmark_not_run: true`.
 */
class AiProgrammingRuntimeTelemetryEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'event_name',
        'event_phase',
        'flow',
        'selected_core',
        'run_id',
        'mission_id',
        'work_order_id',
        'obra_id',
        'route_decision_id',
        'rag_gate_status',
        'context_sufficiency',
        'execution_status',
        'test_status',
        'repair_attempt_count',
        'evidence_completeness',
        'certification_status',
        'blocker_count',
        'duration_ms',
        'cost_estimate_usd',
        'metadata',
        'event_hash',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context_sufficiency' => 'integer',
            'repair_attempt_count' => 'integer',
            'evidence_completeness' => 'integer',
            'blocker_count' => 'integer',
            'duration_ms' => 'integer',
            'cost_estimate_usd' => 'float',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
