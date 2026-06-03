<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A certified-for-review PROPOSAL — the durable artifact the operator wakes up to.
 *
 * HARD INVARIANT: the loop NEVER merges to main. This model enforces it structurally
 * at the persistence boundary: every save forces `merged_to_main = false` and the
 * status to `certified_for_review`, so no code path — buggy or otherwise — can ever
 * record a loop-merged change. Merging is exclusively the operator's action, OUTSIDE
 * the loop. This is the structural guard, not a discouragement.
 */
class AtlasLoopProposal extends Model
{
    use HasUuids;

    public const STATUS_CERTIFIED = 'certified_for_review';

    protected $table = 'atlas_loop_proposals';

    protected $fillable = [
        'campaign_id', 'task_id', 'schema_version', 'status', 'objective', 'provider',
        'target_path', 'diff_text', 'proposal_hash', 'metric', 'acceptance_hash',
        'scenarios_explored', 'scenarios_accepted', 'winning_scenario', 'merged_to_main', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'metric' => 'array',
            'scenarios_explored' => 'integer',
            'scenarios_accepted' => 'integer',
            'merged_to_main' => 'boolean',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Structural never-merge guard: the loop's ledger can only ever hold
        // certified-for-review proposals. Enforced on EVERY save.
        static::saving(function (AtlasLoopProposal $proposal): void {
            $proposal->merged_to_main = false;
            $proposal->status = self::STATUS_CERTIFIED;
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AtlasLoopCampaign::class, 'campaign_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasLoopTask::class, 'task_id');
    }
}
