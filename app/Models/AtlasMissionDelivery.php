<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * G3 — uma mission delivery disparada via HTTP/Job, com resultado durável para
 * polling. O resultado carrega o envelope do AtlasMissionService (branch, nunca
 * merge — main intocada por construção).
 */
class AtlasMissionDelivery extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    protected $table = 'atlas_mission_deliveries';

    protected $fillable = [
        'schema_version', 'request', 'status', 'requested_via', 'operator_id',
        'mission_id', 'branch', 'result', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
