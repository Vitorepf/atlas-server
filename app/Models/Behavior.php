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
        'created_by',
        'source_capture_ids',
        'activation_rules',
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
