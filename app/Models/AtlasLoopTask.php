<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A durable, restart-safe TASK in a campaign queue. Its `payload` carries the SPEC
 * needed to rebuild the scoped base workspace at claim time (the real-repo target
 * path + the frozen test bodies + acceptance), so a task survives a process restart.
 * Claim/lease columns let parallel workers grind without double-processing and let a
 * crashed worker's task be reclaimed once its lease expires.
 */
class AtlasLoopTask extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEFERRED = 'deferred';

    public const SOURCE_DISCOVERY = 'discovery';

    public const SOURCE_GENERATOR = 'generator';

    public const SOURCE_LOOPBACK = 'loopback';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SEED = 'seed';

    protected $table = 'atlas_loop_tasks';

    protected $fillable = [
        'campaign_id', 'schema_version', 'status', 'source', 'self_contained', 'target_path', 'objective',
        'payload', 'priority', 'attempts', 'max_attempts', 'dedupe_key', 'acceptance_hash',
        'claimed_by', 'claimed_at', 'lease_expires_at', 'heartbeat_at', 'result',
    ];

    protected function casts(): array
    {
        return [
            'self_contained' => 'boolean',
            'payload' => 'array',
            'priority' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'result' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AtlasLoopCampaign::class, 'campaign_id');
    }
}
