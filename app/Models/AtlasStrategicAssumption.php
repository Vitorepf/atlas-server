<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasStrategicAssumption extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'scope_type',
        'scope_id',
        'statement',
        'confidence',
        'invalidators',
        'evidence_refs',
        'review_at',
        'assumption_hash',
    ];

    protected function casts(): array
    {
        return [
            'invalidators' => 'array',
            'evidence_refs' => 'array',
            'review_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
