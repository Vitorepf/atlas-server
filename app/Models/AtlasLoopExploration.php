<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An EXPLORATION audit record — the durable memory of "the 19 that didn't work" for a
 * task: how many scenarios were explored, how many passed, whether a winner emerged,
 * whether the deep search converged, and the reasons candidates were rejected. This
 * is the loop's growing experience, kept even when no proposal results.
 */
class AtlasLoopExploration extends Model
{
    use HasUuids;

    protected $table = 'atlas_loop_explorations';

    protected $fillable = [
        'campaign_id', 'task_id', 'schema_version', 'objective', 'provider',
        'scenarios_explored', 'scenarios_accepted', 'has_winner', 'converged',
        'rejected_reasons', 'attempt_metrics', 'elapsed_seconds',
    ];

    protected function casts(): array
    {
        return [
            'scenarios_explored' => 'integer',
            'scenarios_accepted' => 'integer',
            'has_winner' => 'boolean',
            'converged' => 'boolean',
            'rejected_reasons' => 'array',
            'attempt_metrics' => 'array',
            'elapsed_seconds' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
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
