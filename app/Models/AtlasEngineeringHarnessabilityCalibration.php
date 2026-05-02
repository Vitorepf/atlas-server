<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasEngineeringHarnessabilityCalibration extends Model
{
    use HasUuids;

    protected $fillable = [
        'sample_limit',
        'sample_count',
        'confidence',
        'sample_window_json',
        'current_thresholds_json',
        'recommended_thresholds_json',
        'bucket_metrics_json',
        'outcome_metrics_json',
        'recommendations_json',
        'metadata',
        'calibrated_at',
    ];

    protected function casts(): array
    {
        return [
            'sample_limit' => 'integer',
            'sample_count' => 'integer',
            'sample_window_json' => 'array',
            'current_thresholds_json' => 'array',
            'recommended_thresholds_json' => 'array',
            'bucket_metrics_json' => 'array',
            'outcome_metrics_json' => 'array',
            'recommendations_json' => 'array',
            'metadata' => 'array',
            'calibrated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
