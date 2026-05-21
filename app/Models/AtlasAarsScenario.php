<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAarsScenario extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'surface_id',
        'domain',
        'flow_id',
        'scenario_type',
        'scope_hash',
        'objective_hash',
        'objective',
        'world_state',
        'assumptions',
        'constraints',
        'evidence_refs',
        'scenario_hash',
    ];

    protected function casts(): array
    {
        return [
            'world_state' => 'array',
            'assumptions' => 'array',
            'constraints' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
