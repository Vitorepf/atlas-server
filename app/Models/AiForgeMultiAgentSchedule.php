<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiForgeMultiAgentSchedule extends Model
{
    use HasUuids;

    protected $table = 'ai_forge_multi_agent_schedules';

    protected $fillable = [
        'schema_version',
        'uuid',
        'intake_id',
        'mission_id',
        'work_order_id',
        'obra_id',
        'task_summary',
        'risk_band',
        'recommended_agent_count',
        'roles_summary',
        'role_assignments',
        'ownership_map',
        'non_overlap_constraints',
        'dependency_order',
        'integration_plan',
        'conflict_risks',
        'verification_plan',
        'evidence_refs',
        'status',
        'blocker_reason',
        'schedule_hash',
    ];

    protected function casts(): array
    {
        return [
            'recommended_agent_count' => 'integer',
            'roles_summary' => 'array',
            'role_assignments' => 'array',
            'ownership_map' => 'array',
            'non_overlap_constraints' => 'array',
            'dependency_order' => 'array',
            'conflict_risks' => 'array',
            'verification_plan' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Stable canonical projection.
     *
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'schema' => $this->schema_version,
            'schedule_id' => $this->id,
            'schedule_uuid' => $this->uuid,
            'intake_id' => $this->intake_id,
            'mission_id' => $this->mission_id,
            'work_order_id' => $this->work_order_id,
            'obra_id' => $this->obra_id,
            'task_summary' => $this->task_summary,
            'risk_band' => $this->risk_band,
            'recommended_agent_count' => (int) $this->recommended_agent_count,
            'roles_summary' => (array) ($this->roles_summary ?? []),
            'role_assignments' => array_values((array) ($this->role_assignments ?? [])),
            'ownership_map' => (array) ($this->ownership_map ?? []),
            'non_overlap_constraints' => array_values((array) ($this->non_overlap_constraints ?? [])),
            'dependency_order' => array_values((array) ($this->dependency_order ?? [])),
            'integration_plan' => $this->integration_plan,
            'conflict_risks' => array_values((array) ($this->conflict_risks ?? [])),
            'verification_plan' => (array) ($this->verification_plan ?? []),
            'evidence_refs' => array_values((array) ($this->evidence_refs ?? [])),
            'status' => $this->status,
            'blocker_reason' => $this->blocker_reason,
            'schedule_hash' => $this->schedule_hash,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
