<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasEngineeringBenchmarkRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'suite_id',
        'benchmark_key',
        'provider',
        'model',
        'mode',
        'case_set_hash',
        'status',
        'total_cases',
        'passed_cases',
        'failed_cases',
        'baseline_run_id',
        'pass_rate',
        'pass_rate_delta',
        'average_score',
        'average_score_delta',
        'duration_ms',
        'trend_status',
        'harness_version',
        'total_attempts',
        'failed_control_count',
        'blocked_control_count',
        'skipped_required_control_count',
        'failed_test_count',
        'open_review_finding_count',
        'blocking_review_finding_count',
        'changed_files_count',
        'risk_flag_count',
        'total_tokens',
        'cost_microusd',
        'telemetry_coverage_count',
        'quality_metrics_json',
        'release_gate_status',
        'release_gate_profile',
        'release_gate_policy_json',
        'release_gate_failures_json',
        'release_gate_warnings_json',
        'rollout_status',
        'rollout_policy_json',
        'rollout_decision_at',
        'outcome_status',
        'outcome_score',
        'outcome_json',
        'outcome_recorded_at',
        'outcome_recorded_by',
        'runner_options_json',
        'summary_json',
        'started_at',
        'finished_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'total_cases' => 'integer',
            'passed_cases' => 'integer',
            'failed_cases' => 'integer',
            'pass_rate' => 'float',
            'pass_rate_delta' => 'float',
            'average_score' => 'float',
            'average_score_delta' => 'float',
            'duration_ms' => 'integer',
            'total_attempts' => 'integer',
            'failed_control_count' => 'integer',
            'blocked_control_count' => 'integer',
            'skipped_required_control_count' => 'integer',
            'failed_test_count' => 'integer',
            'open_review_finding_count' => 'integer',
            'blocking_review_finding_count' => 'integer',
            'changed_files_count' => 'integer',
            'risk_flag_count' => 'integer',
            'total_tokens' => 'integer',
            'cost_microusd' => 'integer',
            'telemetry_coverage_count' => 'integer',
            'quality_metrics_json' => 'array',
            'release_gate_policy_json' => 'array',
            'release_gate_failures_json' => 'array',
            'release_gate_warnings_json' => 'array',
            'rollout_policy_json' => 'array',
            'rollout_decision_at' => 'immutable_datetime',
            'outcome_score' => 'integer',
            'outcome_json' => 'array',
            'outcome_recorded_at' => 'immutable_datetime',
            'runner_options_json' => 'array',
            'summary_json' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function suite(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringBenchmarkSuite::class, 'suite_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AtlasEngineeringBenchmarkResult::class, 'benchmark_run_id');
    }
}
