<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiForgeWorkPacketWorkcellRoute extends Model
{
    use HasUuids;

    protected $table = 'ai_forge_work_packet_workcell_routes';

    protected $fillable = [
        'schema_version',
        'uuid',
        'intake_id',
        'work_packet_id',
        'work_packet_canonical_id',
        'execution_cycle_id',
        'multi_agent_schedule_id',
        'workcell',
        'agent_profile',
        'parallelizable',
        'requires_human_review',
        'route_reasons',
        'ownership_paths',
        'status',
        'route_hash',
    ];

    protected function casts(): array
    {
        return [
            'parallelizable' => 'boolean',
            'requires_human_review' => 'boolean',
            'route_reasons' => 'array',
            'ownership_paths' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function executionCycle(): BelongsTo
    {
        return $this->belongsTo(AiForgeWorkPacketExecutionCycle::class, 'execution_cycle_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AiForgeMultiAgentSchedule::class, 'multi_agent_schedule_id');
    }

    /**
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'schema_version' => $this->schema_version,
            'route_id' => $this->id,
            'route_uuid' => $this->uuid,
            'intake_id' => $this->intake_id,
            'work_packet_id' => $this->work_packet_id,
            'work_packet_canonical_id' => $this->work_packet_canonical_id,
            'execution_cycle_id' => $this->execution_cycle_id,
            'multi_agent_schedule_id' => $this->multi_agent_schedule_id,
            'workcell' => $this->workcell,
            'agent_profile' => $this->agent_profile,
            'parallelizable' => (bool) $this->parallelizable,
            'requires_human_review' => (bool) $this->requires_human_review,
            'route_reasons' => array_values((array) ($this->route_reasons ?? [])),
            'ownership_paths' => array_values((array) ($this->ownership_paths ?? [])),
            'status' => $this->status,
            'route_hash' => $this->route_hash,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
