<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasRuntimeEfficiencyReplay extends Model
{
    use HasUuids;

    protected $fillable = [
        'decision_id',
        'schema_version',
        'status',
        'flow_id',
        'baseline_path',
        'recommended_path',
        'candidates',
        'winning_candidate',
        'evidence_refs',
        'replay_hash',
    ];

    protected function casts(): array
    {
        return [
            'candidates' => 'array',
            'winning_candidate' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
