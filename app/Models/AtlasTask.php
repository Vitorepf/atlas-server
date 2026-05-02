<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AtlasTask extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'domain',
        'source_capture_id',
        'project_id',
        'project_step_id',
        'routine_id',
        'routine_occurrence_date',
        'due_at',
        'planned_for_date',
        'planned_start_at',
        'planned_end_at',
        'estimated_minutes',
        'energy_required',
        'urgency_score',
        'impact_score',
        'effort_score',
        'priority_score',
        'planning_status',
        'completed_at',
        'execution_mode',
        'friction_level',
        'emotional_resistance',
        'clarity_level',
        'starter_step',
        'minimum_viable_action',
        'if_then_plan',
        'reward_hint',
        'failure_reason_last',
        'attempt_count',
        'recovery_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'planned_for_date' => 'immutable_date',
            'routine_occurrence_date' => 'immutable_date',
            'planned_start_at' => 'immutable_datetime',
            'planned_end_at' => 'immutable_datetime',
            'estimated_minutes' => 'integer',
            'urgency_score' => 'integer',
            'impact_score' => 'integer',
            'effort_score' => 'integer',
            'priority_score' => 'integer',
            'completed_at' => 'immutable_datetime',
            'friction_level' => 'integer',
            'emotional_resistance' => 'integer',
            'clarity_level' => 'integer',
            'attempt_count' => 'integer',
            'recovery_count' => 'integer',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(AtlasProject::class, 'project_id');
    }

    public function projectStep(): BelongsTo
    {
        return $this->belongsTo(AtlasProjectStep::class, 'project_step_id');
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(AtlasRoutine::class, 'routine_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AtlasTaskEvent::class, 'task_id');
    }

    public function engineeringEvidence(): HasMany
    {
        return $this->hasMany(AtlasEngineeringEvidence::class, 'task_id');
    }

    public function engineeringBlueprints(): HasMany
    {
        return $this->hasMany(AtlasEngineeringBlueprint::class, 'task_id');
    }

    public function engineeringRuns(): HasMany
    {
        return $this->hasMany(AtlasEngineeringRun::class, 'task_id')
            ->latest('created_at');
    }

    public function memoryEntries(): HasMany
    {
        return $this->hasMany(AtlasMemoryEntry::class, 'task_id')
            ->latest('recorded_at');
    }

    public function engineeringTestCases(): HasMany
    {
        return $this->hasMany(AtlasEngineeringTestCase::class, 'task_id');
    }

    public function engineeringReviewFindings(): HasMany
    {
        return $this->hasMany(AtlasEngineeringReviewFinding::class, 'task_id')
            ->latest('created_at');
    }

    public function engineeringBenchmarkCases(): HasMany
    {
        return $this->hasMany(AtlasEngineeringBenchmarkCase::class, 'task_id');
    }

    public function engineeringBenchmarkResults(): HasMany
    {
        return $this->hasMany(AtlasEngineeringBenchmarkResult::class, 'task_id')
            ->latest('created_at');
    }

    public function calendarBlocks(): HasMany
    {
        return $this->hasMany(AtlasCalendarBlock::class, 'task_id');
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(AtlasProjectBlocker::class, 'task_id');
    }

    public function openBlockers(): HasMany
    {
        return $this->hasMany(AtlasProjectBlocker::class, 'task_id')
            ->where('status', 'open')
            ->orderByDesc('updated_at');
    }
}
