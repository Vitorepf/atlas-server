<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiMission extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'title',
        'raw_prompt',
        'normalized_intent',
        'mission_type',
        'status',
        'autonomy_level',
        'risk_level',
        'definition_of_done',
        'context_summary',
        'primary_domain',
        'secondary_domains',
        'current_step',
        'blocker_reason',
        'evidence_pack_hash',
        'certification_hash',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'definition_of_done' => 'array',
            'secondary_domains' => 'array',
            'proactive_origin' => 'array',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(AiObjective::class, 'mission_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(AiWorkOrder::class, 'mission_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AiMissionEvent::class, 'mission_id');
    }

    public function evidenceRefs(): HasMany
    {
        return $this->hasMany(AiMissionEvidenceRef::class, 'mission_id');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(AiMissionCertification::class, 'mission_id');
    }

    public function latestCertification(): HasOne
    {
        return $this->hasOne(AiMissionCertification::class, 'mission_id')->latestOfMany();
    }
}
