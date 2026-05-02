<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiReportFinding extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'ai_report_findings';

    protected $fillable = [
        'run_id', 'report_date', 'report_type', 'metric', 'direction',
        'magnitude_pct', 'explained_fraction', 'affected_n',
        'attribution_dimensions', 'evidence', 'signals',
        'confidence_score', 'confidence_band', 'suggested_action_seed',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'magnitude_pct' => 'float',
            'explained_fraction' => 'float',
            'affected_n' => 'integer',
            'attribution_dimensions' => 'array',
            'evidence' => 'array',
            'signals' => 'array',
            'confidence_score' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiPerformanceReportRun::class, 'run_id');
    }
}
