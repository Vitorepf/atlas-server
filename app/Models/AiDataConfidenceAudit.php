<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per Trust Gate evaluation. Persisted by TrustGateService::evaluate()
 * regardless of engine_version (live, shadow, replay) so the operator can
 * observe trust-score trends over time without engaging the engine cutover.
 *
 * Composes with AiPerformanceReportRun via run_id (nullable for early shadow
 * runs that don't yet have a parent run row).
 */
class AiDataConfidenceAudit extends Model
{
    use HasUuids;

    /**
     * No updated_at — audit rows are immutable. evaluated_at + created_at
     * carry the temporal meaning explicitly.
     */
    public $timestamps = false;

    protected $table = 'ai_data_confidence_audit';

    protected $fillable = [
        'run_id',
        'report_date',
        'report_type',
        'trust_score',
        'trust_level',
        'coverage',
        'usable_for_attribution',
        'aggregator_version',
        'mixed_aggregator_versions',
        'sample_count',
        'score_components',
        'audit_trail',
        'evaluated_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'trust_score' => 'float',
            'coverage' => 'float',
            'usable_for_attribution' => 'boolean',
            'mixed_aggregator_versions' => 'boolean',
            'sample_count' => 'integer',
            'score_components' => 'array',
            'audit_trail' => 'array',
            'evaluated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiPerformanceReportRun::class, 'run_id');
    }
}
