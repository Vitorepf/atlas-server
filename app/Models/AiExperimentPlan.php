<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiExperimentPlan extends Model
{
    use HasUuids;

    protected $table = 'ai_experiment_plans';

    protected $fillable = [
        'schema_version',
        'uuid',
        'strategy_run_id',
        'opportunity_id',
        'venture_blueprint_id',
        'experiment_id',
        'hypothesis',
        'hypothesis_kind',
        'success_metric',
        'design',
        'sample',
        'budget_max',
        'currency',
        'duration',
        'safety_gates',
        'status',
        'decision',
        'result',
        'experiment_hash',
    ];

    protected function casts(): array
    {
        return [
            'success_metric' => 'array',
            'design' => 'array',
            'sample' => 'array',
            'budget_max' => 'float',
            'duration' => 'array',
            'safety_gates' => 'array',
            'decision' => 'array',
            'result' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
