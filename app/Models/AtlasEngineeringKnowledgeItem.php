<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasEngineeringKnowledgeItem extends Model
{
    use HasUuids;

    public const STATUSES = [
        'active',
        'draft',
        'archived',
        'deprecated',
    ];

    protected $fillable = [
        'slug',
        'title',
        'category',
        'status',
        'priority',
        'source_type',
        'canonical_path',
        'source_hash',
        'content_hash',
        'summary',
        'body_excerpt',
        'tags_json',
        'related_paths_json',
        'capabilities_json',
        'decisions_json',
        'maintenance_json',
        'metadata',
        'indexed_at',
        'last_verified_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'tags_json' => 'array',
            'related_paths_json' => 'array',
            'capabilities_json' => 'array',
            'decisions_json' => 'array',
            'maintenance_json' => 'array',
            'metadata' => 'array',
            'indexed_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNull('archived_at');
    }

    public function docLinks(): HasMany
    {
        return $this->hasMany(AtlasEngineeringDocLink::class, 'knowledge_item_id');
    }
}
