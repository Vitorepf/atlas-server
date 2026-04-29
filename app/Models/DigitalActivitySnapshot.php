<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DigitalActivitySnapshot extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'source',
        'snapshot_date',
        'snapshot_timezone',
        'computed_at',
        'signal_count',
        'total_screen_time_min',
        'pickups_count',
        'first_offensive_use_min_after_wake',
        'deep_work_sessions_count',
        'deep_work_total_min',
        'notifications_received',
        'notifications_actioned',
        'curated_input_min',
        'algorithmic_input_min',
        'intentional_entertainment_min',
        'default_entertainment_min',
        'communication_primary_min',
        'communication_shallow_min',
        'market_min',
        'focus_mode_active_min',
        'category_breakdown',
        'source_breakdown',
        'raw_rize_data',
        'raw_screentime_data',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'immutable_date',
            'computed_at' => 'immutable_datetime',
            'signal_count' => 'integer',
            'total_screen_time_min' => 'integer',
            'pickups_count' => 'integer',
            'first_offensive_use_min_after_wake' => 'integer',
            'deep_work_sessions_count' => 'integer',
            'deep_work_total_min' => 'integer',
            'notifications_received' => 'integer',
            'notifications_actioned' => 'integer',
            'curated_input_min' => 'integer',
            'algorithmic_input_min' => 'integer',
            'intentional_entertainment_min' => 'integer',
            'default_entertainment_min' => 'integer',
            'communication_primary_min' => 'integer',
            'communication_shallow_min' => 'integer',
            'market_min' => 'integer',
            'focus_mode_active_min' => 'array',
            'category_breakdown' => 'array',
            'source_breakdown' => 'array',
            'raw_rize_data' => 'array',
            'raw_screentime_data' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
