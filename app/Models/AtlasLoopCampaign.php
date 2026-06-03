<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A 24h Evolution Loop CAMPAIGN — the durable unit a supervisor drives for a long
 * horizon. It owns budgets, rolling counters, the crash-recovery lock lease and the
 * heartbeat. The actual work lives in its tasks / proposals / explorations.
 */
class AtlasLoopCampaign extends Model
{
    use HasUuids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABORTED = 'aborted';

    public const STATUS_KILLED = 'killed';

    protected $table = 'atlas_loop_campaigns';

    protected $fillable = [
        'schema_version', 'status', 'goal', 'base_workspace', 'provider', 'worktree_path',
        'max_seconds', 'max_proposals', 'max_tasks', 'max_usd_cents', 'config',
        'tasks_processed', 'proposals_count', 'scenarios_explored', 'refills', 'loopbacks',
        'spend_usd_cents', 'elapsed_seconds', 'totals',
        'stop_reason', 'kill_switch', 'lock_token', 'lock_expires_at', 'heartbeat_at',
        'started_at', 'paused_at', 'finished_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'max_seconds' => 'integer',
            'max_proposals' => 'integer',
            'max_tasks' => 'integer',
            'max_usd_cents' => 'integer',
            'config' => 'array',
            'tasks_processed' => 'integer',
            'proposals_count' => 'integer',
            'scenarios_explored' => 'integer',
            'refills' => 'integer',
            'loopbacks' => 'integer',
            'spend_usd_cents' => 'integer',
            'elapsed_seconds' => 'integer',
            'totals' => 'array',
            'kill_switch' => 'boolean',
            'lock_expires_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(AtlasLoopTarget::class, 'campaign_id');
    }

    /**
     * The fixed-budget discipline: a single source of truth for "should the campaign
     * stop now". Uses the PERSISTED elapsed_seconds (not now()-started_at) so paused
     * time never burns budget and a crash-resumed campaign resumes against REMAINING
     * budget. 0 on any cap means "unbounded by that axis".
     */
    public function budgetStopReason(): ?string
    {
        if ($this->kill_switch) {
            return 'kill_switch';
        }
        if ($this->max_seconds > 0 && $this->elapsed_seconds >= $this->max_seconds) {
            return 'time_budget_reached';
        }
        if ($this->max_tasks > 0 && $this->tasks_processed >= $this->max_tasks) {
            return 'max_tasks';
        }
        if ($this->max_proposals > 0 && $this->proposals_count >= $this->max_proposals) {
            return 'max_proposals';
        }
        if ($this->max_usd_cents > 0 && $this->spend_usd_cents >= $this->max_usd_cents) {
            return 'cost_cap';
        }

        return null;
    }

    public function isOverBudget(): bool
    {
        return $this->budgetStopReason() !== null;
    }

    /**
     * Heartbeat + budget accrual in one write: advance liveness, fold in the wall-clock
     * the last grind consumed (so budget tracks real spent time across pauses/crashes),
     * and any provider cost the execution layer reported.
     */
    public function beat(int $addElapsedSeconds = 0, int $addSpendCents = 0): void
    {
        $this->heartbeat_at = now();
        if ($addElapsedSeconds > 0) {
            $this->elapsed_seconds = (int) $this->elapsed_seconds + $addElapsedSeconds;
        }
        if ($addSpendCents > 0) {
            $this->spend_usd_cents = (int) $this->spend_usd_cents + $addSpendCents;
        }
        $this->save();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AtlasLoopTask::class, 'campaign_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AtlasLoopProposal::class, 'campaign_id');
    }

    public function explorations(): HasMany
    {
        return $this->hasMany(AtlasLoopExploration::class, 'campaign_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_RUNNING, self::STATUS_PAUSED], true);
    }
}
