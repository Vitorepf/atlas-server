<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiForgeOutcomeMemory extends Model
{
    use HasUuids;

    protected $table = 'ai_forge_outcome_memories';

    protected $fillable = [
        'schema_version',
        'uuid',
        'intake_id',
        'work_packet_id',
        'work_packet_canonical_id',
        'execution_cycle_id',
        'cycle_uuid',
        'outcome_status',
        'execution_mode',
        'evidence_kinds',
        'aedpds_drivers',
        'aedpds_gate_status',
        'aedpds_doctrine_hash',
        'aedpds_gate_hash',
        'aedpds_effectiveness',
        'learning_candidates',
        'failure_capsule',
        'should_promote_to_aemor',
        'human_review_required',
        'outcome_memory_hash',
    ];

    protected function casts(): array
    {
        return [
            'evidence_kinds' => 'array',
            'aedpds_drivers' => 'array',
            'aedpds_effectiveness' => 'array',
            'learning_candidates' => 'array',
            'failure_capsule' => 'array',
            'should_promote_to_aemor' => 'boolean',
            'human_review_required' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function executionCycle(): BelongsTo
    {
        return $this->belongsTo(AiForgeWorkPacketExecutionCycle::class, 'execution_cycle_id');
    }

    /**
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'schema_version' => $this->schema_version,
            'memory_id' => $this->id,
            'memory_uuid' => $this->uuid,
            'intake_id' => $this->intake_id,
            'work_packet_id' => $this->work_packet_id,
            'packet_id' => $this->work_packet_canonical_id,
            'work_packet_canonical_id' => $this->work_packet_canonical_id,
            'execution_cycle_id' => $this->execution_cycle_id,
            'cycle_uuid' => $this->cycle_uuid,
            'outcome_status' => $this->outcome_status,
            'execution_mode' => $this->execution_mode,
            'evidence_kinds' => array_values((array) ($this->evidence_kinds ?? [])),
            'aedpds_drivers' => array_values((array) ($this->aedpds_drivers ?? [])),
            'aedpds_gate_status' => $this->aedpds_gate_status,
            'aedpds_doctrine_hash' => $this->aedpds_doctrine_hash,
            'aedpds_gate_hash' => $this->aedpds_gate_hash,
            'aedpds_effectiveness' => (array) ($this->aedpds_effectiveness ?? []),
            'learning_candidates' => array_values((array) ($this->learning_candidates ?? [])),
            'failure_capsule' => $this->failure_capsule,
            'should_promote_to_aemor' => (bool) $this->should_promote_to_aemor,
            'human_review_required' => (bool) $this->human_review_required,
            'outcome_memory_hash' => $this->outcome_memory_hash,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
