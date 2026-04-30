<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasProjectStep extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'active_task_id',
        'step_order',
        'title',
        'description',
        'status',
        'step_type',
        'expected_output',
        'acceptance_criteria',
        'estimated_minutes',
        'energy_required',
        'friction_level',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'estimated_minutes' => 'integer',
            'friction_level' => 'integer',
            'metadata' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AtlasProject::class, 'project_id');
    }

    public function activeTask(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'active_task_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AtlasTask::class, 'project_step_id');
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(AtlasProjectBlocker::class, 'project_step_id');
    }

    public function openBlockers(): HasMany
    {
        return $this->hasMany(AtlasProjectBlocker::class, 'project_step_id')
            ->where('status', 'open')
            ->orderByDesc('updated_at');
    }
}
