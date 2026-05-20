<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAemorLearningSignal extends Model
{
    use HasUuids;

    protected $fillable = [
        'episode_id',
        'outcome_id',
        'schema_version',
        'signal_type',
        'status',
        'claim',
        'confidence',
        'scope_type',
        'scope_id',
        'use_when',
        'do_not_use_when',
        'evidence_refs',
        'metadata',
        'signal_hash',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'use_when' => 'array',
            'do_not_use_when' => 'array',
            'evidence_refs' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
