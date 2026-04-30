<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiQualityAction extends Model
{
    use HasUuids;

    protected $fillable = [
        'evaluation_id',
        'trace_id',
        'remediation_trace_id',
        'thread_id',
        'session_id',
        'action_type',
        'status',
        'priority',
        'reason',
        'flags',
        'payload',
        'result',
        'error_message',
        'dedupe_key',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'evaluation_id' => 'string',
            'trace_id' => 'string',
            'remediation_trace_id' => 'string',
            'thread_id' => 'string',
            'session_id' => 'string',
            'priority' => 'integer',
            'flags' => 'array',
            'payload' => 'array',
            'result' => 'array',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(AiQualityEvaluation::class, 'evaluation_id');
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }

    public function remediationTrace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'remediation_trace_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiThread::class, 'thread_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }
}
