<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiBudgetEnvelope extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'scope_type',
        'scope_ref',
        'max_cost',
        'max_tokens',
        'max_runtime_seconds',
        'max_tool_calls',
        'max_external_calls',
        'current_cost',
        'current_tokens',
        'current_runtime_seconds',
        'current_tool_calls',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'max_cost' => 'decimal:6',
            'max_tokens' => 'integer',
            'max_runtime_seconds' => 'integer',
            'max_tool_calls' => 'integer',
            'max_external_calls' => 'integer',
            'current_cost' => 'decimal:6',
            'current_tokens' => 'integer',
            'current_runtime_seconds' => 'integer',
            'current_tool_calls' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
