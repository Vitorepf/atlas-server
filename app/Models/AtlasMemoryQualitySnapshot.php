<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasMemoryQualitySnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace',
        'workspace_hash',
        'source_type',
        'source_id',
        'status',
        'score',
        'components_json',
        'counts_json',
        'ratios_json',
        'issues_json',
        'recommendations_json',
        'metadata',
        'snapshot_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'components_json' => 'array',
            'counts_json' => 'array',
            'ratios_json' => 'array',
            'issues_json' => 'array',
            'recommendations_json' => 'array',
            'metadata' => 'array',
            'snapshot_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
