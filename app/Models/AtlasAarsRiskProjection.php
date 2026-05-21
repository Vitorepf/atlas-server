<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAarsRiskProjection extends Model
{
    use HasUuids;

    protected $fillable = [
        'simulation_id',
        'schema_version',
        'status',
        'risk_level',
        'risks',
        'mitigations',
        'rollback_requirements',
        'operator_gates',
        'evidence_refs',
        'risk_hash',
    ];

    protected function casts(): array
    {
        return [
            'risks' => 'array',
            'mitigations' => 'array',
            'rollback_requirements' => 'array',
            'operator_gates' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
