<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiScheduledTask extends Model
{
    use HasUuids;

    protected $fillable = [
        'title',
        'prompt',
        'schedule',
        'kind',
        'skill_ids',
        'target_platform',
        'target_device_id',
        'workspace',
        'enabled',
        'next_run_at',
        'last_run_at',
        'last_status',
        'last_output_path',
        'repeat_remaining',
        'context_from_task_ids',
        'wrap_response',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'skill_ids' => 'array',
            'target_device_id' => 'string',
            'enabled' => 'boolean',
            'next_run_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'repeat_remaining' => 'integer',
            'context_from_task_ids' => 'array',
            'wrap_response' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
