<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiGateRun extends Model
{
    use HasUuids;

    protected $table = 'ai_gate_runs';

    protected $fillable = [
        'schema_version',
        'uuid',
        'gate_type',
        'target_type',
        'target_id',
        'status',
        'checked_requirements',
        'missing_requirements',
        'evidence_refs',
        'gate_hash',
        'mission_id',
    ];

    protected function casts(): array
    {
        return [
            'checked_requirements' => 'array',
            'missing_requirements' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
