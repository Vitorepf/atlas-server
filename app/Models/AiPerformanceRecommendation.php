<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPerformanceRecommendation extends Model
{
    use HasUuids;

    protected $table = 'ai_performance_recommendations';

    protected $fillable = [
        'user_id', 'finding_id', 'origin_report_date', 'kind',
        'target_metric', 'target_dimension', 'expected_impact',
        'state', 'state_history', 'baseline_snapshot', 'observed_impact',
        'measurement_due_at', 'measurement_window_days', 'priority_score',
        'snoozed_until', 'closed_at', 'closed_reason', 'superseded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'origin_report_date' => 'date',
            'target_dimension' => 'array',
            'expected_impact' => 'array',
            'state_history' => 'array',
            'baseline_snapshot' => 'array',
            'observed_impact' => 'array',
            'measurement_due_at' => 'immutable_datetime',
            'measurement_window_days' => 'integer',
            'priority_score' => 'integer',
            'snoozed_until' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(AiReportFinding::class, 'finding_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }
}
