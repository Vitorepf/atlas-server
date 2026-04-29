<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_id',
        'client_id',
        'kind',
        'status',
        'priority',
        'agent_slug',
        'provider',
        'model',
        'input_text',
        'prompt',
        'context_refs',
        'payload',
        'result_text',
        'result_json',
        'error_code',
        'error_message',
        'available_at',
        'reserved_at',
        'started_at',
        'finished_at',
        'attempts',
        'max_attempts',
        'timeout_seconds',
        'worker_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'trace_id' => 'string',
            'client_id' => 'string',
            'priority' => 'integer',
            'context_refs' => 'array',
            'payload' => 'array',
            'result_json' => 'array',
            'available_at' => 'immutable_datetime',
            'reserved_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'timeout_seconds' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }

    public function attemptHistory(): HasMany
    {
        return $this->hasMany(AiJobAttempt::class, 'ai_job_id');
    }
}
