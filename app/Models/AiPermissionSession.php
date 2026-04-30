<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiPermissionSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'thread_id',
        'session_id',
        'workspace',
        'mode',
        'allowed_tools',
        'allowed_paths',
        'denied_patterns',
        'expires_at',
        'granted_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'thread_id' => 'string',
            'session_id' => 'string',
            'allowed_tools' => 'array',
            'allowed_paths' => 'array',
            'denied_patterns' => 'array',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
