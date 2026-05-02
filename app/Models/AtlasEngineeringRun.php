<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AtlasEngineeringRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'task_id',
        'project_id',
        'project_step_id',
        'blueprint_snapshot_id',
        'blueprint_id',
        'trace_id',
        'context_pack_id',
        'workspace_path_hash',
        'workspace_label',
        'provider_strategy_json',
        'context_pack_hash',
        'harnessability_score',
        'status',
        'decision',
        'score',
        'max_attempts',
        'attempt_count',
        'started_at',
        'finished_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider_strategy_json' => 'array',
            'harnessability_score' => 'integer',
            'score' => 'integer',
            'max_attempts' => 'integer',
            'attempt_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AtlasProject::class, 'project_id');
    }

    public function projectStep(): BelongsTo
    {
        return $this->belongsTo(AtlasProjectStep::class, 'project_step_id');
    }

    public function blueprintSnapshot(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringBlueprint::class, 'blueprint_snapshot_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AtlasEngineeringRunAttempt::class, 'engineering_run_id')
            ->orderBy('attempt_number');
    }

    public function patchArtifacts(): HasMany
    {
        return $this->hasMany(AtlasEngineeringPatchArtifact::class, 'engineering_run_id')
            ->latest('created_at');
    }

    public function controlResults(): HasMany
    {
        return $this->hasMany(AtlasEngineeringControlResult::class, 'engineering_run_id')
            ->latest('created_at');
    }

    public function testRuns(): HasMany
    {
        return $this->hasMany(AtlasEngineeringTestRun::class, 'engineering_run_id')
            ->latest('created_at');
    }

    public function reviewFindings(): HasMany
    {
        return $this->hasMany(AtlasEngineeringReviewFinding::class, 'engineering_run_id')
            ->latest('created_at');
    }

    public function operatorActions(): HasMany
    {
        return $this->hasMany(AtlasEngineeringRunOperatorAction::class, 'engineering_run_id')
            ->latest('acted_at')
            ->latest('created_at');
    }

    public function benchmarkResults(): HasMany
    {
        return $this->hasMany(AtlasEngineeringBenchmarkResult::class, 'engineering_run_id')
            ->latest('created_at');
    }

    public function memoryEntries(): HasMany
    {
        return $this->hasMany(AtlasMemoryEntry::class, 'engineering_run_id')
            ->latest('recorded_at');
    }

    public function contextPack(): HasOne
    {
        return $this->hasOne(AtlasEngineeringContextPack::class, 'engineering_run_id');
    }
}
