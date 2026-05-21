<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAaelPromotionDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'cycle_id',
        'experiment_id',
        'schema_version',
        'status',
        'trust_level',
        'promotion_gate',
        'risk_controls',
        'operator_action',
        'evidence_refs',
        'decision_hash',
    ];

    protected function casts(): array
    {
        return [
            'promotion_gate' => 'array',
            'risk_controls' => 'array',
            'operator_action' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
