<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasMemoryEntryRelation extends Model
{
    use HasUuids;

    public const TYPES = [
        'duplicate',
        'conflict',
    ];

    public const STATUSES = [
        'open',
        'resolved',
        'dismissed',
    ];

    protected $fillable = [
        'source_memory_entry_id',
        'target_memory_entry_id',
        'relation_type',
        'status',
        'confidence',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_memory_entry_id' => 'string',
            'target_memory_entry_id' => 'string',
            'confidence' => 'float',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function sourceMemoryEntry(): BelongsTo
    {
        return $this->belongsTo(AtlasMemoryEntry::class, 'source_memory_entry_id');
    }

    public function targetMemoryEntry(): BelongsTo
    {
        return $this->belongsTo(AtlasMemoryEntry::class, 'target_memory_entry_id');
    }
}
