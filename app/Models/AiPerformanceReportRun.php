<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per execution of the report engine pipeline.
 *
 * Lifecycle (per ReportOrchestrator):
 *   1. INSERT row with status='started', engine_version, report_date.
 *   2. UPDATE row at completion with completed_at, duration_ms, layer_timings, status.
 *
 * Status semantics:
 *   - started: row inserted but pipeline not yet finished. Stale 'started' rows
 *     (started_at > 2h ago) indicate killed processes; can be cleaned by a separate job.
 *   - completed: pipeline succeeded end-to-end. trust_score, finding_count, etc. populated.
 *   - failed: orchestrator caught a fatal error. layer_errors carries the exception message.
 *   - partial: one or more non-fatal layers failed; the report still emitted with degraded
 *     content. layer_errors lists which layers fell back. Other layers' timings are present.
 *
 * No timestamps() (no updated_at) because the row is updated at most twice in its lifetime
 * (insert + complete) and the timestamp semantics are explicit via started_at/completed_at.
 */
class AiPerformanceReportRun extends Model
{
    use HasUuids;

    /**
     * Disable Laravel's automatic updated_at — completed_at carries the meaning instead.
     * created_at is set by the migration default (useCurrent).
     */
    public $timestamps = false;

    protected $fillable = [
        'report_date',
        'report_type',
        'engine_version',
        'run_mode',
        'status',
        'started_at',
        'completed_at',
        'duration_ms',
        'traces_processed',
        'trust_score',
        'finding_count',
        'recommendation_count',
        'layer_timings',
        'layer_errors',
        'schema_version',
        'input_hash',
        'input_snapshot',
        'output_hash',
        'output_snapshot',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'traces_processed' => 'integer',
            'trust_score' => 'float',
            'finding_count' => 'integer',
            'recommendation_count' => 'integer',
            'layer_timings' => 'array',
            'layer_errors' => 'array',
            'schema_version' => 'integer',
            'input_snapshot' => 'array',
            'output_snapshot' => 'array',
        ];
    }
}
