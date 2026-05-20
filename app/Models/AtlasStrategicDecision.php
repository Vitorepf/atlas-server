<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasStrategicDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'question_hash',
        'question',
        'reality_scope',
        'recommended_action',
        'why_now',
        'why_not',
        'confidence',
        'options',
        'tradeoffs',
        'assumption_refs',
        'risk_refs',
        'opportunity_refs',
        'freshness',
        'next_actions',
        'evidence_refs',
        'claim_policy',
        'decision_hash',
    ];

    protected function casts(): array
    {
        return [
            'reality_scope' => 'array',
            'confidence' => 'float',
            'options' => 'array',
            'tradeoffs' => 'array',
            'assumption_refs' => 'array',
            'risk_refs' => 'array',
            'opportunity_refs' => 'array',
            'freshness' => 'array',
            'next_actions' => 'array',
            'evidence_refs' => 'array',
            'claim_policy' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
