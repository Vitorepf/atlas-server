<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasRuntimeEfficiencyPolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'flow_id',
        'domain',
        'recommended_path',
        'context_budget_multiplier',
        'tool_budget_multiplier',
        'layer_overrides',
        'quality_stats',
        'policy_rules',
        'evidence_refs',
        'policy_hash',
    ];

    protected function casts(): array
    {
        return [
            'context_budget_multiplier' => 'float',
            'tool_budget_multiplier' => 'float',
            'layer_overrides' => 'array',
            'quality_stats' => 'array',
            'policy_rules' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
