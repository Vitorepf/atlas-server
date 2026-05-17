<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiTemporalCertification extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'window_started_at',
        'window_ended_at',
        'flow_deltas',
        'rival_deltas',
        'claim_policy',
        'blockers',
        'certification_hash',
        'certified_at',
    ];

    protected function casts(): array
    {
        return [
            'window_started_at' => 'immutable_datetime',
            'window_ended_at' => 'immutable_datetime',
            'flow_deltas' => 'array',
            'rival_deltas' => 'array',
            'claim_policy' => 'array',
            'blockers' => 'array',
            'certified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
