<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasRuntimeEfficiencyDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'surface_id',
        'domain',
        'flow_id',
        'runtime_mode',
        'path',
        'prompt_hash',
        'complexity_score',
        'risk_score',
        'context_budget_tokens',
        'tool_budget',
        'subagent_budget',
        'context_minimum_pack',
        'layer_admissions',
        'tool_policy',
        'provider_fit',
        'verification_plan',
        'adaptive_policy',
        'counterfactual_replay',
        'enforcement_policy',
        'quality_prediction',
        'efficiency_risks',
        'evidence_refs',
        'claim_policy',
        'decision_hash',
    ];

    protected function casts(): array
    {
        return [
            'complexity_score' => 'integer',
            'risk_score' => 'integer',
            'context_budget_tokens' => 'integer',
            'tool_budget' => 'integer',
            'subagent_budget' => 'integer',
            'context_minimum_pack' => 'array',
            'layer_admissions' => 'array',
            'tool_policy' => 'array',
            'provider_fit' => 'array',
            'verification_plan' => 'array',
            'adaptive_policy' => 'array',
            'counterfactual_replay' => 'array',
            'enforcement_policy' => 'array',
            'quality_prediction' => 'array',
            'efficiency_risks' => 'array',
            'evidence_refs' => 'array',
            'claim_policy' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(AtlasRuntimeEfficiencyOutcome::class, 'decision_id');
    }
}
