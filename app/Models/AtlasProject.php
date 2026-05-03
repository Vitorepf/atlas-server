<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AtlasProject extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'status',
        'domain',
        'source_capture_id',
        'goal',
        'next_action',
        'project_type',
        'desired_outcome',
        'minimum_viable_outcome',
        'definition_of_done',
        'why_now',
        'deadline_at',
        'deadline_kind',
        'priority',
        'energy_profile',
        'avoidance_reason',
        'active_next_task_id',
        'current_step_id',
        'last_touched_at',
        'next_review_at',
        'completed_at',
        'paused_until',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'deadline_at' => 'immutable_datetime',
            'last_touched_at' => 'immutable_datetime',
            'next_review_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'paused_until' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function sourceCapture(): BelongsTo
    {
        return $this->belongsTo(Capture::class, 'source_capture_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AtlasTask::class, 'project_id');
    }

    public function memoryEntries(): HasMany
    {
        return $this->hasMany(AtlasMemoryEntry::class, 'project_id')
            ->latest('recorded_at');
    }

    public function activeNextTask(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'active_next_task_id');
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(AtlasProjectStep::class, 'current_step_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AtlasProjectStep::class, 'project_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AtlasProjectEvent::class, 'project_id');
    }

    public function planProposals(): HasMany
    {
        return $this->hasMany(AtlasProjectPlanProposal::class, 'project_id');
    }

    public function engineeringProjectBlueprints(): HasMany
    {
        return $this->hasMany(AtlasEngineeringProjectBlueprint::class, 'project_id')
            ->latest('version');
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(AtlasProjectBlocker::class, 'project_id');
    }

    public function openBlockers(): HasMany
    {
        return $this->hasMany(AtlasProjectBlocker::class, 'project_id')
            ->where('status', 'open')
            ->orderByDesc('updated_at');
    }
}
