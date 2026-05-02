<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasEngineeringCodeModule extends Model
{
    use HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'layer',
        'root_path',
        'primary_language',
        'status',
        'owner',
        'description',
        'docs_status',
        'file_count',
        'symbol_count',
        'route_count',
        'command_count',
        'migration_count',
        'test_count',
        'source_hash',
        'docs_hash',
        'tags_json',
        'related_docs_json',
        'related_tests_json',
        'metadata',
        'indexed_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'file_count' => 'integer',
            'symbol_count' => 'integer',
            'route_count' => 'integer',
            'command_count' => 'integer',
            'migration_count' => 'integer',
            'test_count' => 'integer',
            'tags_json' => 'array',
            'related_docs_json' => 'array',
            'related_tests_json' => 'array',
            'metadata' => 'array',
            'indexed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNull('archived_at');
    }

    public function symbols(): HasMany
    {
        return $this->hasMany(AtlasEngineeringCodeSymbol::class, 'module_id');
    }

    public function docLinks(): HasMany
    {
        return $this->hasMany(AtlasEngineeringDocLink::class, 'module_id');
    }
}
