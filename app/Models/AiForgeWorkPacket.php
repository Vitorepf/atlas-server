<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiForgeWorkPacket extends Model
{
    use HasUuids;

    protected $table = 'ai_forge_work_packets';

    protected $fillable = [
        'schema_version',
        'uuid',
        'intake_id',
        'packet_position',
        'packet_id',
        'title',
        'objective',
        'scope',
        'expected_files',
        'dependencies',
        'risks',
        'acceptance_criteria',
        'required_evidence',
        'suggested_tests',
        'status',
        'owner',
        'role_slot',
        'risk_band',
        'packet_hash',
    ];

    protected function casts(): array
    {
        return [
            'packet_position' => 'integer',
            'expected_files' => 'array',
            'dependencies' => 'array',
            'risks' => 'array',
            'acceptance_criteria' => 'array',
            'required_evidence' => 'array',
            'suggested_tests' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(AiForgeIntake::class, 'intake_id');
    }

    /**
     * Stable canonical projection matching `atlas.forge.work_packet.v1`.
     *
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'schema' => $this->schema_version,
            'packet_id' => $this->packet_id,
            'packet_uuid' => $this->uuid,
            'intake_id' => $this->intake_id,
            'position' => (int) $this->packet_position,
            'title' => $this->title,
            'objective' => $this->objective,
            'scope' => $this->scope,
            'expected_files' => array_values((array) ($this->expected_files ?? [])),
            'dependencies' => array_values((array) ($this->dependencies ?? [])),
            'risks' => array_values((array) ($this->risks ?? [])),
            'acceptance_criteria' => array_values((array) $this->acceptance_criteria),
            'required_evidence' => array_values((array) $this->required_evidence),
            'suggested_tests' => array_values((array) ($this->suggested_tests ?? [])),
            'status' => $this->status,
            'owner' => $this->owner,
            'role_slot' => $this->role_slot,
            'risk_band' => $this->risk_band,
            'packet_hash' => $this->packet_hash,
        ];
    }
}
