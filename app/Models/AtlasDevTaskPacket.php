<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AtlasDevTaskPacket extends Model
{
    use HasUuids;

    protected $table = 'atlas_dev_task_packets';

    protected $fillable = [
        'schema_version',
        'uuid',
        'run_id',
        'task_id',
        'objective',
        'task_class',
        'risk_band',
        'workspace_slug',
        'allowed_files',
        'forbidden_files',
        'context_refs',
        'expected_files',
        'suggested_tests',
        'acceptance_criteria',
        'required_evidence',
        'source',
        'task_packet_hash',
    ];

    protected function casts(): array
    {
        return [
            'allowed_files' => 'array',
            'forbidden_files' => 'array',
            'context_refs' => 'array',
            'expected_files' => 'array',
            'suggested_tests' => 'array',
            'acceptance_criteria' => 'array',
            'required_evidence' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function contextGate(): HasOne
    {
        return $this->hasOne(AtlasDevContextGate::class, 'task_packet_id');
    }

    public function outcomeMemory(): HasOne
    {
        return $this->hasOne(AtlasDevOutcomeMemory::class, 'task_packet_id');
    }

    public function runCertification(): HasOne
    {
        return $this->hasOne(AtlasDevRunCertification::class, 'task_packet_id');
    }
}
