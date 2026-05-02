<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMetricDailySnapshot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'ai_metric_daily_snapshots';

    protected $fillable = [
        'snapshot_date',
        'metric',
        'aggregator_version',
        'n_traces',
        'value_mean',
        'value_p50',
        'value_p95',
        'value_sum',
        'value_rate',
        'rate_numerator',
        'rate_denominator',
        'distribution_sample',
        'ewma_state',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'n_traces' => 'integer',
            'value_mean' => 'float',
            'value_p50' => 'float',
            'value_p95' => 'float',
            'value_sum' => 'float',
            'value_rate' => 'float',
            'rate_numerator' => 'integer',
            'rate_denominator' => 'integer',
            'distribution_sample' => 'array',
            'ewma_state' => 'array',
            'computed_at' => 'immutable_datetime',
        ];
    }
}
