<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasSoftwareTwinSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'target',
        'target_path',
        'software_twin',
        'quality_score',
        'blockers',
        'claim_policy',
        'snapshot_hash',
    ];

    protected function casts(): array
    {
        return [
            'software_twin' => 'array',
            'quality_score' => 'array',
            'blockers' => 'array',
            'claim_policy' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
