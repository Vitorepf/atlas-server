<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAarsSimulation extends Model
{
    use HasUuids;

    protected $fillable = [
        'scenario_id',
        'schema_version',
        'status',
        'mode',
        'options',
        'predicted_outcomes',
        'impact_model',
        'uncertainty',
        'required_validation',
        'claim_policy',
        'evidence_refs',
        'simulation_hash',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'predicted_outcomes' => 'array',
            'impact_model' => 'array',
            'uncertainty' => 'array',
            'required_validation' => 'array',
            'claim_policy' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
