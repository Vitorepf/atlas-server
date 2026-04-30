<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderHandoff extends Model
{
    use HasUuids;

    protected $fillable = [
        'thread_id',
        'session_id',
        'from_provider',
        'to_provider',
        'reason',
        'brief_text',
        'brief_json',
        'compaction_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'thread_id' => 'string',
            'session_id' => 'string',
            'brief_json' => 'array',
            'compaction_id' => 'string',
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

    public function compaction(): BelongsTo
    {
        return $this->belongsTo(AiCompaction::class, 'compaction_id');
    }
}
