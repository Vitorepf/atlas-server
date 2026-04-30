<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCompaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'thread_id',
        'session_id',
        'reason',
        'source_position_start',
        'source_position_end',
        'source_message_count',
        'summary',
        'structured_state',
        'token_estimate_before',
        'token_estimate_after',
        'quality_gate_status',
        'provider',
        'model',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'thread_id' => 'string',
            'session_id' => 'string',
            'source_position_start' => 'integer',
            'source_position_end' => 'integer',
            'source_message_count' => 'integer',
            'structured_state' => 'array',
            'token_estimate_before' => 'integer',
            'token_estimate_after' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
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
