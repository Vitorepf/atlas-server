<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAaelEvolutionExperiment extends Model
{
    use HasUuids;

    protected $fillable = [
        'cycle_id',
        'opportunity_id',
        'aweos_execution_id',
        'intelligence_factory_gap_id',
        'schema_version',
        'status',
        'lane',
        'spec_packet',
        'impact_simulation',
        'execution_plan',
        'verification_plan',
        'rollback_plan',
        'learning_plan',
        'evidence_refs',
        'experiment_hash',
    ];

    protected function casts(): array
    {
        return [
            'spec_packet' => 'array',
            'impact_simulation' => 'array',
            'execution_plan' => 'array',
            'verification_plan' => 'array',
            'rollback_plan' => 'array',
            'learning_plan' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
