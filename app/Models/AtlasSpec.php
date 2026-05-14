<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canonical SDD spec record. content_json is the source of truth;
 * content_markdown is human-readable projection only (never authoritative
 * per data-model-and-services.md:297).
 */
class AtlasSpec extends Model
{
    use HasUuids;

    protected $fillable = [
        'operation_id', 'project_id', 'work_item_id', 'title', 'type',
        'status', 'version', 'risk_level', 'content_hash', 'content_json',
        'content_markdown', 'approved_at', 'superseded_at', 'superseded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'version' => 'integer',
            'approved_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(AtlasOperation::class, 'operation_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(AtlasProgrammingWorkItem::class, 'work_item_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(AtlasRequirement::class, 'spec_id');
    }

    public function assumptions(): HasMany
    {
        return $this->hasMany(AtlasAssumption::class, 'spec_id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(AtlasPlan::class, 'spec_id');
    }

    public function traceability(): HasMany
    {
        return $this->hasMany(AtlasSpecTraceability::class, 'spec_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }
}
