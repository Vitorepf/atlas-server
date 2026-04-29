<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiWorkerEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'worker_id',
        'provider',
        'ai_job_id',
        'ai_job_attempt_id',
        'event_type',
        'severity',
        'message',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_job_id' => 'string',
            'ai_job_attempt_id' => 'string',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AiJobAttempt::class, 'ai_job_attempt_id');
    }
}
