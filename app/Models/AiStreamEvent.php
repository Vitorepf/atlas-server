<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiStreamEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'trace_id',
        'ai_job_id',
        'ai_job_attempt_id',
        'sequence',
        'event_type',
        'channel',
        'content',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'trace_id' => 'string',
            'ai_job_id' => 'string',
            'ai_job_attempt_id' => 'string',
            'sequence' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
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
