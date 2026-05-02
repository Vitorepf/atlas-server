<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasMemoryEntryUsage extends Model
{
    use HasUuids;

    public const FEEDBACK_ACTIONS = [
        'useful',
        'not_useful',
        'wrong_context',
        'stale',
        'too_much',
        'corrected',
        'dismissed',
    ];

    protected $fillable = [
        'memory_entry_id',
        'trace_id',
        'context_snapshot_id',
        'thread_id',
        'session_id',
        'memory_type',
        'scope_type',
        'scope_id',
        'source_type',
        'source_id',
        'position',
        'included_reason',
        'source_ref_json',
        'context_payload_json',
        'metadata',
        'feedback_action',
        'feedback_score',
        'feedback_comment',
        'feedback_recorded_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'memory_entry_id' => 'string',
            'trace_id' => 'string',
            'context_snapshot_id' => 'string',
            'thread_id' => 'string',
            'session_id' => 'string',
            'position' => 'integer',
            'source_ref_json' => 'array',
            'context_payload_json' => 'array',
            'metadata' => 'array',
            'feedback_score' => 'integer',
            'feedback_recorded_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function memoryEntry(): BelongsTo
    {
        return $this->belongsTo(AtlasMemoryEntry::class, 'memory_entry_id');
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }

    public function contextSnapshot(): BelongsTo
    {
        return $this->belongsTo(AiContextSnapshot::class, 'context_snapshot_id');
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
