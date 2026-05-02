<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AtlasVerbatimMemory extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const TYPES = [
        'decision',
        'command',
        'evidence',
        'quote',
        'requirement',
        'review',
        'failure',
    ];

    public const PRIVACY_CLASSES = [
        'normal',
        'private',
        'sensitive',
        'secret',
    ];

    public const REDACTION_STATUSES = [
        'clean',
        'redacted',
    ];

    public const STATUSES = [
        'active',
        'inactive',
        'archived',
    ];

    protected $fillable = [
        'memory_entry_id',
        'verbatim_type',
        'scope_type',
        'scope_id',
        'project_id',
        'task_id',
        'engineering_run_id',
        'trace_id',
        'session_id',
        'user_id',
        'title',
        'verbatim_text',
        'redacted_text',
        'summary',
        'privacy_class',
        'external_ai_allowed',
        'redaction_status',
        'content_hash',
        'redacted_hash',
        'source_type',
        'source_id',
        'source_label',
        'status',
        'tags',
        'metadata',
        'recorded_at',
        'last_used_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'memory_entry_id' => 'string',
            'project_id' => 'string',
            'task_id' => 'string',
            'engineering_run_id' => 'string',
            'trace_id' => 'string',
            'session_id' => 'string',
            'external_ai_allowed' => 'boolean',
            'tags' => 'array',
            'metadata' => 'array',
            'recorded_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNull('archived_at');
    }

    public function memoryEntry(): BelongsTo
    {
        return $this->belongsTo(AtlasMemoryEntry::class, 'memory_entry_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AtlasProject::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }

    public function engineeringRun(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringRun::class, 'engineering_run_id');
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }
}
