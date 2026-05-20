<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasIntelligenceFactoryEvolutionEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'capability_id',
        'schema_version',
        'source_type',
        'source_id',
        'event_type',
        'status',
        'payload',
        'evidence_refs',
        'event_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
