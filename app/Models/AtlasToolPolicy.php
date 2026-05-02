<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasToolPolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'scope_type',
        'scope_id',
        'tool_slug',
        'enabled',
        'required_when_json',
        'failure_policy',
        'timeout_seconds',
        'thresholds_json',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'required_when_json' => 'array',
            'timeout_seconds' => 'integer',
            'thresholds_json' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
