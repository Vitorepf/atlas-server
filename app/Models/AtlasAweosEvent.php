<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAweosEvent extends Model
{
    use HasUuids;

    protected $table = 'atlas_aweos_events';

    protected $fillable = [
        'execution_id',
        'schema_version',
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
