<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasRealityRelationship extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'source_entity_id',
        'target_entity_id',
        'relationship_type',
        'weight',
        'attributes',
        'evidence_refs',
        'relationship_hash',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'attributes' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
