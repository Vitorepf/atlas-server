<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasProjectPlanProposal extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'source_capture_id',
        'status',
        'proposed_title',
        'planner_version',
        'input_hash',
        'project_type',
        'avoidance_profile',
        'desired_outcome',
        'definition_of_done',
        'minimum_useful_result',
        'first_milestone',
        'first_next_action',
        'estimated_energy',
        'estimated_duration_minutes',
        'priority_suggestion',
        'confidence',
        'phases_json',
        'steps_json',
        'risks_json',
        'questions_json',
        'rationale',
        'metadata',
        'accepted_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_duration_minutes' => 'integer',
            'confidence' => 'float',
            'phases_json' => 'array',
            'steps_json' => 'array',
            'risks_json' => 'array',
            'questions_json' => 'array',
            'metadata' => 'array',
            'accepted_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AtlasProject::class, 'project_id');
    }

    public function sourceCapture(): BelongsTo
    {
        return $this->belongsTo(Capture::class, 'source_capture_id');
    }
}
