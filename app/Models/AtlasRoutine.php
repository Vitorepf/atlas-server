<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AtlasRoutine extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'status',
        'domain',
        'source_capture_id',
        'project_id',
        'frequency',
        'weekdays',
        'timezone',
        'preferred_time',
        'estimated_minutes',
        'energy_required',
        'priority',
        'execution_mode',
        'friction_level',
        'emotional_resistance',
        'clarity_level',
        'starter_step',
        'minimum_viable_action',
        'if_then_plan',
        'reward_hint',
        'next_occurrence_date',
        'last_generated_for_date',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'estimated_minutes' => 'integer',
            'friction_level' => 'integer',
            'emotional_resistance' => 'integer',
            'clarity_level' => 'integer',
            'next_occurrence_date' => 'immutable_date',
            'last_generated_for_date' => 'immutable_date',
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

    public function tasks(): HasMany
    {
        return $this->hasMany(AtlasTask::class, 'routine_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AtlasRoutineEvent::class, 'routine_id');
    }
}
