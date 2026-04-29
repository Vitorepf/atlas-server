<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiJobAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_job_id',
        'attempt_number',
        'worker_id',
        'provider',
        'model',
        'command',
        'command_hash',
        'prompt_hash',
        'response_hash',
        'status',
        'exit_code',
        'duration_ms',
        'output_text',
        'stdout_excerpt',
        'stderr_excerpt',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ai_job_id' => 'string',
            'attempt_number' => 'integer',
            'command' => 'array',
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }
}
