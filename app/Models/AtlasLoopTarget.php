<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discovery TARGET — a candidate file the loop researched and scored (the Ladder
 * "Sources" stage, automated at repo scale). Deterministic + provider-free: the
 * discovery service admits only self-contained files the cp -R + plain-`php` grind
 * can honestly pin, then scores them so the supervisor pulls the highest-value ones
 * first. Loop-back spawns new targets from each Result, so the queue self-sustains.
 * The generator's RED-verification remains the authoritative "is this real work" gate
 * downstream — discovery never asserts an improvement is real.
 */
class AtlasLoopTarget extends Model
{
    use HasUuids;

    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const ORIGIN_DISCOVERY = 'discovery';

    public const ORIGIN_NEIGHBOR = 'neighbor';

    public const ORIGIN_SIBLING = 'sibling';

    public const ORIGIN_FAILURE = 'failure';

    protected $table = 'atlas_loop_targets';

    protected $fillable = [
        'campaign_id', 'schema_version', 'target_path', 'target_key', 'content_hash', 'status',
        'score', 'self_contained_score', 'improvement_score', 'novelty_score', 'signals', 'lineage',
        'attempts', 'max_attempts', 'claimed_by', 'claimed_at', 'lease_expires_at', 'reason',
        // ARBOR-GRAFT T1 — idea-tree edges (advisory; never gates — see AtlasLoopIdeaTreeAccessor invariant).
        'parent_target_id', 'depth', 'node_kind', 'tree_status', 'hypothesis', 'node_insight',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'self_contained_score' => 'float',
            'improvement_score' => 'float',
            'novelty_score' => 'float',
            'signals' => 'array',
            'lineage' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            // ARBOR-GRAFT T1 — idea-tree edges.
            'depth' => 'integer',
            'hypothesis' => 'array',
            'node_insight' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AtlasLoopCampaign::class, 'campaign_id');
    }

    /**
     * ARBOR-GRAFT T1 — the parent idea-tree node (null = root / flat-ledger row). Advisory edge only.
     */
    public function parentTarget(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_target_id');
    }
}
