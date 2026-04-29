<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'sync_log';

    protected $fillable = [
        'device_id',
        'synced_at',
        'captures_uploaded',
        'captures_downloaded',
        'checkins_uploaded',
        'checkins_downloaded',
        'passive_signals_uploaded',
        'passive_signals_downloaded',
        'health_snapshots_uploaded',
        'health_snapshots_downloaded',
        'behaviors_uploaded',
        'behaviors_downloaded',
        'behavior_logs_uploaded',
        'behavior_logs_downloaded',
        'digital_sessions_uploaded',
        'digital_sessions_downloaded',
        'digital_snapshots_uploaded',
        'digital_snapshots_downloaded',
        'duration_ms',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'immutable_datetime',
            'captures_uploaded' => 'integer',
            'captures_downloaded' => 'integer',
            'checkins_uploaded' => 'integer',
            'checkins_downloaded' => 'integer',
            'passive_signals_uploaded' => 'integer',
            'passive_signals_downloaded' => 'integer',
            'health_snapshots_uploaded' => 'integer',
            'health_snapshots_downloaded' => 'integer',
            'behaviors_uploaded' => 'integer',
            'behaviors_downloaded' => 'integer',
            'behavior_logs_uploaded' => 'integer',
            'behavior_logs_downloaded' => 'integer',
            'digital_sessions_uploaded' => 'integer',
            'digital_sessions_downloaded' => 'integer',
            'digital_snapshots_uploaded' => 'integer',
            'digital_snapshots_downloaded' => 'integer',
            'duration_ms' => 'integer',
            'metadata' => 'array',
        ];
    }
}
