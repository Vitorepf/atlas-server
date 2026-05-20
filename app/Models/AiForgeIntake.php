<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiForgeIntake extends Model
{
    use HasUuids;

    protected $table = 'ai_forge_intakes';

    protected $fillable = [
        'schema_version',
        'uuid',
        'origin',
        'recommended_forge_mode',
        'obra_title',
        'workspace_slug',
        'original_user_intent',
        'normalized_intent',
        'scope_assessment',
        'risk_assessment',
        'ambiguity_assessment',
        'risk_band',
        'mission_id',
        'escalation_packet_id',
        'escalation_packet_hash',
        'promotion_reason',
        'promotion_triggers',
        'definition_of_done',
        'required_evidence',
        'evidence_refs',
        'context_refs',
        'context_pack_hash',
        'rich_input_payload',
        'rich_input_schema_version',
        'context_operations',
        'context_operations_hash',
        'constraints',
        'non_goals',
        'sdd_spec',
        'current_dev_findings',
        'completed_dev_actions',
        'incomplete_dev_actions',
        'status',
        'blocker_reason',
        'intake_hash',
        'actor_type',
    ];

    protected function casts(): array
    {
        return [
            'promotion_triggers' => 'array',
            'definition_of_done' => 'array',
            'required_evidence' => 'array',
            'evidence_refs' => 'array',
            'context_refs' => 'array',
            'rich_input_payload' => 'array',
            'context_operations' => 'array',
            'constraints' => 'array',
            'non_goals' => 'array',
            'sdd_spec' => 'array',
            'current_dev_findings' => 'array',
            'completed_dev_actions' => 'array',
            'incomplete_dev_actions' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function workPackets(): HasMany
    {
        return $this->hasMany(AiForgeWorkPacket::class, 'intake_id')
            ->orderBy('packet_position');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(AiForgeMilestone::class, 'intake_id')
            ->orderBy('position');
    }
}
