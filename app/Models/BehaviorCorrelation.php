<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviorCorrelation extends Model
{
    use HasUuids;

    protected $fillable = [
        'behavior_id',
        'outcome_type',
        'outcome_source',
        'window_days',
        'yes_count',
        'no_count',
        'outcome_avg_yes',
        'outcome_avg_no',
        'difference_pct',
        'confidence_level',
        'confounders_detected',
        'sample_sufficient',
        'computed_at',
        'computed_by_provider',
        'computed_by_version',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'window_days' => 'integer',
            'yes_count' => 'integer',
            'no_count' => 'integer',
            'outcome_avg_yes' => 'float',
            'outcome_avg_no' => 'float',
            'difference_pct' => 'float',
            'confounders_detected' => 'array',
            'sample_sufficient' => 'boolean',
            'computed_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function behavior(): BelongsTo
    {
        return $this->belongsTo(Behavior::class);
    }
}
