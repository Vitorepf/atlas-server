<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasVaultSyncItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'direction',
        'operation',
        'status',
        'path',
        'source_type',
        'source_id',
        'semantic_note_id',
        'content_hash',
        'conflict_type',
        'summary',
        'frontmatter_json',
        'links_json',
        'metadata',
        'reviewed_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'frontmatter_json' => 'array',
            'links_json' => 'array',
            'metadata' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
