<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasEngineeringCodeSymbol extends Model
{
    use HasUuids;

    protected $fillable = [
        'module_id',
        'symbol_type',
        'symbol_name',
        'file_path',
        'line_start',
        'line_end',
        'language',
        'signature',
        'namespace',
        'parent_symbol',
        'visibility',
        'status',
        'docs_status',
        'source_hash',
        'related_doc_ids_json',
        'metadata',
        'indexed_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'module_id' => 'string',
            'line_start' => 'integer',
            'line_end' => 'integer',
            'related_doc_ids_json' => 'array',
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

    public function module(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringCodeModule::class, 'module_id');
    }

    public function docLinks(): HasMany
    {
        return $this->hasMany(AtlasEngineeringDocLink::class, 'symbol_id');
    }
}
