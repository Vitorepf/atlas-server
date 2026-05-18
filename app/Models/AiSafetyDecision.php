<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSafetyDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'decision_type',
        'requested_action',
        'decision',
        'risk_assessment_id',
        'policy_profile_id',
        'reasons',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function policyProfile(): BelongsTo
    {
        return $this->belongsTo(AiPolicyProfile::class, 'policy_profile_id');
    }

    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(AiRiskAssessment::class, 'risk_assessment_id');
    }
}
