<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasProgrammingActionManifest extends Model
{
    use HasUuids;

    protected $fillable = [
        'action_id',
        'plan_id',
        'stage',
        'tool',
        'programming_tool',
        'permission_mode',
        'dry_run',
        'gate_effect',
        'next_action',
        'changed_files_json',
        'rollback_json',
        'inputs_json',
        'outputs_json',
        'payload_json',
        'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'programming_tool' => 'boolean',
            'dry_run' => 'boolean',
            'changed_files_json' => 'array',
            'rollback_json' => 'array',
            'inputs_json' => 'array',
            'outputs_json' => 'array',
            'payload_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
