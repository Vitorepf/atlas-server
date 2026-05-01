<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiTelemetryEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'event_key',
        'correlation_id',
        'trace_id',
        'thread_id',
        'session_id',
        'ai_job_id',
        'ai_job_attempt_id',
        'client_id',
        'surface',
        'runtime',
        'app_version',
        'cli_version',
        'provider',
        'model',
        'agent_slug',
        'event_name',
        'event_phase',
        'occurred_at_client',
        'received_at',
        'duration_ms',
        'numeric_value',
        'unit',
        'metadata',
        'privacy',
        'schema_version',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at_client' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'numeric_value' => 'float',
            'metadata' => 'array',
            'privacy' => 'array',
            'schema_version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
