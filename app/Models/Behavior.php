<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Behavior extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'slug',
        'category',
        'input_type',
        'question_text',
        'default_value',
        'parent_factor',
        'factor_condition',
        'target_outcomes',
        'expected_lag',
        'expected_direction',
        'granularity_level',
        'sensitivity_level',
        'derived_from',
        'operator_confirmed',
        'created_by',
        'source_capture_ids',
        'activation_rules',
        'lifecycle_status',
        'paused_until',
        'last_prompted_at',
        'prompt_cadence_days',
        'auto_suppress_reason',
        'show_in_morning_briefing',
        'priority_score',
        'streak_yes',
        'streak_no',
        'total_yes_count',
        'total_no_count',
        'relational_privacy',
        'activated_at',
        'archived_at',
        'promoted_to_object_type',
        'promoted_to_object_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_capture_ids' => 'array',
            'activation_rules' => 'array',
            'target_outcomes' => 'array',
            'derived_from' => 'array',
            'operator_confirmed' => 'boolean',
            'paused_until' => 'immutable_datetime',
            'last_prompted_at' => 'immutable_date',
            'prompt_cadence_days' => 'integer',
            'show_in_morning_briefing' => 'boolean',
            'priority_score' => 'integer',
            'streak_yes' => 'integer',
            'streak_no' => 'integer',
            'total_yes_count' => 'integer',
            'total_no_count' => 'integer',
            'relational_privacy' => 'boolean',
            'activated_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BehaviorLog::class);
    }
}
