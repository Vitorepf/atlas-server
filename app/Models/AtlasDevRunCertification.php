<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasDevRunCertification extends Model
{
    use HasUuids;

    protected $table = 'atlas_dev_run_certifications';

    protected $fillable = [
        'schema_version',
        'uuid',
        'run_id',
        'task_id',
        'task_packet_id',
        'context_gate_id',
        'failure_capsule_id',
        'outcome_memory_id',
        'status',
        'summary',
        'checks',
        'blockers',
        'certification_hash',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'checks' => 'array',
            'blockers' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function taskPacket(): BelongsTo
    {
        return $this->belongsTo(AtlasDevTaskPacket::class, 'task_packet_id');
    }

    public function contextGate(): BelongsTo
    {
        return $this->belongsTo(AtlasDevContextGate::class, 'context_gate_id');
    }

    public function failureCapsule(): BelongsTo
    {
        return $this->belongsTo(AtlasDevFailureCapsule::class, 'failure_capsule_id');
    }

    public function outcomeMemory(): BelongsTo
    {
        return $this->belongsTo(AtlasDevOutcomeMemory::class, 'outcome_memory_id');
    }
}
