<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'thread_id',
        'trace_id',
        'position',
        'role',
        'status',
        'content',
        'provider',
        'model',
        'agent_slug',
        'token_estimate',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'thread_id' => 'string',
            'trace_id' => 'string',
            'position' => 'integer',
            'token_estimate' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiThread::class, 'thread_id');
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }
}
