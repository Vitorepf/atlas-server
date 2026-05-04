<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasPowerEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'host_key',
        'power_session_id',
        'ai_job_id',
        'event_type',
        'severity',
        'message',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'power_session_id' => 'string',
            'ai_job_id' => 'string',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
