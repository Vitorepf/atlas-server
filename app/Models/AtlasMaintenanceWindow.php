<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasMaintenanceWindow extends Model
{
    use HasUuids;

    protected $fillable = [
        'host_key',
        'name',
        'enabled',
        'timezone',
        'wake_time',
        'duration_minutes',
        'days_of_week',
        'last_scheduled_at',
        'last_started_at',
        'last_completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'duration_minutes' => 'integer',
            'days_of_week' => 'array',
            'last_scheduled_at' => 'immutable_datetime',
            'last_started_at' => 'immutable_datetime',
            'last_completed_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
