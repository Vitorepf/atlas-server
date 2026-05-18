<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiForgeLongHorizonState extends Model
{
    use HasUuids;

    protected $table = 'ai_forge_long_horizon_states';

    protected $fillable = [
        'schema_version',
        'uuid',
        'intake_id',
        'obra_title',
        'status',
        'current_milestone',
        'milestone_progress',
        'active_work_packets',
        'completed_work_packets',
        'blockers',
        'evidence_refs',
        'next_action',
        'last_cycle_summary',
        'cycle_count',
        'continuation_context_hash',
        'state_hash',
        'blocker_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'milestone_progress' => 'array',
            'active_work_packets' => 'array',
            'completed_work_packets' => 'array',
            'blockers' => 'array',
            'evidence_refs' => 'array',
            'next_action' => 'array',
            'last_cycle_summary' => 'array',
            'cycle_count' => 'integer',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(AiForgeIntake::class, 'intake_id');
    }

    /**
     * Stable canonical projection matching `atlas.forge.long_horizon_state.v1`.
     *
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'schema' => $this->schema_version,
            'state_id' => $this->id,
            'state_uuid' => $this->uuid,
            'obra_id' => $this->intake_id,
            'obra_title' => $this->obra_title,
            'status' => $this->status,
            'current_milestone' => $this->current_milestone,
            'milestone_progress' => (array) ($this->milestone_progress ?? []),
            'active_work_packets' => array_values((array) ($this->active_work_packets ?? [])),
            'completed_work_packets' => array_values((array) ($this->completed_work_packets ?? [])),
            'blockers' => array_values((array) ($this->blockers ?? [])),
            'evidence_refs' => array_values((array) ($this->evidence_refs ?? [])),
            'next_action' => (array) ($this->next_action ?? []),
            'last_cycle_summary' => $this->last_cycle_summary,
            'cycle_count' => (int) $this->cycle_count,
            'continuation_context_hash' => $this->continuation_context_hash,
            'state_hash' => $this->state_hash,
            'blocker_reason' => $this->blocker_reason,
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
