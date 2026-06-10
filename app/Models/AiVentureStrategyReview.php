<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiVentureStrategyReview extends Model
{
    use HasUuids;

    protected $table = 'ai_venture_strategy_reviews';

    protected $fillable = [
        'schema_version',
        'uuid',
        'venture_id',
        'review_kind',
        'current_stage',
        'recommended_stage',
        'stage_applied',
        'gate_results',
        'gaps',
        'next_actions',
        'playbook',
        'trajectory',
        'analysis',
        'bridged_mission_ids',
        'strategy_memo_id',
        'status',
        'review_hash',
    ];

    protected function casts(): array
    {
        return [
            'stage_applied' => 'boolean',
            'gate_results' => 'array',
            'gaps' => 'array',
            'next_actions' => 'array',
            'playbook' => 'array',
            'trajectory' => 'array',
            'analysis' => 'array',
            'bridged_mission_ids' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
