<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRiskAssessment extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'target_type',
        'target_ref',
        'risk_level',
        'risk_factors',
        'mitigations',
        'residual_risk',
        'assessor_type',
        'assessment_hash',
    ];

    protected function casts(): array
    {
        return [
            'risk_factors' => 'array',
            'mitigations' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
