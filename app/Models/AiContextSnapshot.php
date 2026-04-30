<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiContextSnapshot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'trace_id',
        'thread_id',
        'session_id',
        'provider',
        'model',
        'prompt_hash',
        'context_pack',
        'messages_included',
        'compaction_id',
        'provider_handoff_id',
        'token_estimate',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'trace_id' => 'string',
            'thread_id' => 'string',
            'session_id' => 'string',
            'context_pack' => 'array',
            'messages_included' => 'array',
            'compaction_id' => 'string',
            'provider_handoff_id' => 'string',
            'token_estimate' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
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
