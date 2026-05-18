<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiForgeWorkPacketExecutionCycle extends Model
{
    use HasUuids;

    protected $table = 'ai_forge_work_packet_execution_cycles';

    protected $fillable = [
        'schema_version',
        'uuid',
        'intake_id',
        'work_packet_id',
        'work_packet_canonical_id',
        'long_horizon_state_id',
        'cycle_position',
        'execution_mode',
        'status',
        'execution_plan',
        'expected_artifacts',
        'evidence_refs',
        'gate_result',
        'outcome_status',
        'failure_reason',
        'repair_hook',
        'next_action',
        'started_at',
        'completed_at',
        'cycle_hash',
    ];

    protected function casts(): array
    {
        return [
            'cycle_position' => 'integer',
            'execution_plan' => 'array',
            'expected_artifacts' => 'array',
            'evidence_refs' => 'array',
            'gate_result' => 'array',
            'repair_hook' => 'array',
            'next_action' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(AiForgeIntake::class, 'intake_id');
    }

    public function workPacket(): BelongsTo
    {
        return $this->belongsTo(AiForgeWorkPacket::class, 'work_packet_id');
    }

    public function longHorizonState(): BelongsTo
    {
        return $this->belongsTo(AiForgeLongHorizonState::class, 'long_horizon_state_id');
    }

    /**
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'schema' => $this->schema_version,
            'cycle_id' => $this->id,
            'cycle_uuid' => $this->uuid,
            'intake_id' => $this->intake_id,
            'work_packet_id' => $this->work_packet_id,
            'work_packet_canonical_id' => $this->work_packet_canonical_id,
            'long_horizon_state_id' => $this->long_horizon_state_id,
            'cycle_position' => (int) $this->cycle_position,
            'execution_mode' => $this->execution_mode,
            'status' => $this->status,
            'execution_plan' => (array) ($this->execution_plan ?? []),
            'expected_artifacts' => array_values((array) ($this->expected_artifacts ?? [])),
            'evidence_refs' => array_values((array) ($this->evidence_refs ?? [])),
            'gate_result' => $this->gate_result,
            'outcome_status' => $this->outcome_status,
            'failure_reason' => $this->failure_reason,
            'repair_hook' => $this->repair_hook,
            'next_action' => (array) ($this->next_action ?? []),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'cycle_hash' => $this->cycle_hash,
        ];
    }
}
