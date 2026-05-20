<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasRealityEntity extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'entity_key',
        'entity_type',
        'name',
        'authority_level',
        'freshness_status',
        'observed_at',
        'valid_until',
        'attributes',
        'evidence_refs',
        'source_refs',
        'entity_hash',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'attributes' => 'array',
            'evidence_refs' => 'array',
            'source_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
