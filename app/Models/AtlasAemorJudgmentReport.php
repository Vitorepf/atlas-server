<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAemorJudgmentReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'episode_id',
        'outcome_id',
        'schema_version',
        'status',
        'causality_rank',
        'outcome_attribution',
        'false_learning_gate',
        'repeated_failure_suppression',
        'context_roi_score',
        'patch_quality_fingerprint',
        'memory_conflicts',
        'human_correction',
        'negative_knowledge',
        'provider_skill_reliability',
        'counterfactual_replay',
        'memory_budget',
        'operational_doctrine',
        'risk_prediction',
        'policy_proposals',
        'quality_score',
        'evidence_refs',
        'judgment_hash',
    ];

    protected function casts(): array
    {
        return [
            'causality_rank' => 'array',
            'outcome_attribution' => 'array',
            'false_learning_gate' => 'array',
            'repeated_failure_suppression' => 'array',
            'context_roi_score' => 'array',
            'patch_quality_fingerprint' => 'array',
            'memory_conflicts' => 'array',
            'human_correction' => 'array',
            'negative_knowledge' => 'array',
            'provider_skill_reliability' => 'array',
            'counterfactual_replay' => 'array',
            'memory_budget' => 'array',
            'operational_doctrine' => 'array',
            'risk_prediction' => 'array',
            'policy_proposals' => 'array',
            'quality_score' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
