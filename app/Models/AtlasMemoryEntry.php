<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class AtlasMemoryEntry extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const TYPES = [
        'decision',
        'preference',
        'feedback',
        'technical_context',
        'issue',
        'resolution',
        'benchmark_observation',
        'harness_learning',
        'anti_memory',
        'strategic_insight',
    ];

    public const SCOPES = [
        'global',
        'project',
        'task',
        'engineering_run',
        'workspace',
        'user',
        'session',
    ];

    public const STATUSES = [
        'active',
        'inactive',
        'archived',
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

    protected $fillable = [
        'memory_type',
        'scope_type',
        'scope_id',
        'project_id',
        'task_id',
        'engineering_run_id',
        'trace_id',
        'session_id',
        'user_id',
        'title',
        'redacted_title',
        'body',
        'redacted_body',
        'summary',
        'redacted_summary',
        'importance',
        'priority',
        'confidence',
        'privacy_class',
        'external_ai_allowed',
        'redaction_status',
        'source_type',
        'source_id',
        'source_label',
        'status',
        'tags',
        'metadata',
        'content_hash',
        'recorded_at',
        'last_used_at',
        'archived_at',
        'superseded_by_id',
        'governance_checked_at',
        'privacy_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'string',
            'task_id' => 'string',
            'engineering_run_id' => 'string',
            'trace_id' => 'string',
            'session_id' => 'string',
            'importance' => 'integer',
            'priority' => 'integer',
            'confidence' => 'float',
            'external_ai_allowed' => 'boolean',
            'tags' => 'array',
            'metadata' => 'array',
            'recorded_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'superseded_by_id' => 'string',
            'governance_checked_at' => 'immutable_datetime',
            'privacy_reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNull('archived_at');
    }

    public function scopeForScope(Builder $query, string $scopeType, ?string $scopeId = null): Builder
    {
        $query->where('scope_type', $scopeType);

        return $scopeId === null
            ? $query->whereNull('scope_id')
            : $query->where('scope_id', $scopeId);
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

    public function usages(): HasMany
    {
        return $this->hasMany(AtlasMemoryEntryUsage::class, 'memory_entry_id')
            ->latest('used_at');
    }

    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(AtlasMemoryEntryRelation::class, 'source_memory_entry_id')
            ->latest('updated_at');
    }

    public function incomingRelations(): HasMany
    {
        return $this->hasMany(AtlasMemoryEntryRelation::class, 'target_memory_entry_id')
            ->latest('updated_at');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function supersedes(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_id');
    }

    public function scopeNotSuperseded(Builder $query): Builder
    {
        return $query->whereNull('superseded_by_id');
    }

    public function verbatimMemory(): HasOne
    {
        return $this->hasOne(AtlasVerbatimMemory::class, 'memory_entry_id');
    }
}
