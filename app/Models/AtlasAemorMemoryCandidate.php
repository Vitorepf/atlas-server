<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAemorMemoryCandidate extends Model
{
    use HasUuids;

    protected $fillable = [
        'episode_id',
        'outcome_id',
        'learning_signal_id',
        'memory_delta_id',
        'schema_version',
        'status',
        'memory_type',
        'claim',
        'confidence',
        'scope_type',
        'scope_id',
        'promotion_gate',
        'evidence_refs',
        'metadata',
        'candidate_hash',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'promotion_gate' => 'array',
            'evidence_refs' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
