<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringDocLink extends Model
{
    use HasUuids;

    protected $fillable = [
        'knowledge_item_id',
        'module_id',
        'symbol_id',
        'link_type',
        'status',
        'canonical_path',
        'target_path',
        'doc_hash',
        'target_hash',
        'link_hash',
        'metadata',
        'indexed_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_item_id' => 'string',
            'module_id' => 'string',
            'symbol_id' => 'string',
            'metadata' => 'array',
            'indexed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'current')->whereNull('archived_at');
    }

    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringKnowledgeItem::class, 'knowledge_item_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringCodeModule::class, 'module_id');
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringCodeSymbol::class, 'symbol_id');
    }
}
