<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasHostStatus extends Model
{
    use HasUuids;

    protected $table = 'atlas_host_status';

    protected $fillable = [
        'host_key',
        'hostname',
        'status',
        'agent_available',
        'caffeinate_available',
        'pmset_available',
        'docker_available',
        'on_ac_power',
        'battery_percent',
        'active_power_sessions',
        'active_ai_jobs',
        'last_seen_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'agent_available' => 'boolean',
            'caffeinate_available' => 'boolean',
            'pmset_available' => 'boolean',
            'docker_available' => 'boolean',
            'on_ac_power' => 'boolean',
            'battery_percent' => 'integer',
            'active_power_sessions' => 'integer',
            'active_ai_jobs' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
